<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DistOrder;
use App\Models\DistOrderItem;
use App\Services\InventoryService;
use App\Services\CommissionService;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DistOrderController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService,
        private CommissionService $commissionService,
        private LedgerService $ledgerService
    ) {}

    public function index(Request $request)
    {
        $orders = DistOrder::with(['franchisee', 'user'])
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('order_number', 'like', "%{$search}%")
                       ->orWhereHas('franchisee', fn($f) => $f->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Distribution/Orders/Index', [
            'orders' => $orders,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function show(DistOrder $distOrder)
    {
        $distOrder->load(['franchisee', 'user', 'items.product', 'acceptedBy', 'dispatchedBy']);

        // Attach available batches and HO stock dynamically for the UI
        foreach ($distOrder->items as $item) {
            $item->available_batches = $this->inventoryService->getProductStockAtLocation($item->product_id, 'warehouse', 0);
        }

        return Inertia::render('Distribution/Orders/Show', [
            'order' => $distOrder,
        ]);
    }

    /**
     * Replaces the massive "ordereraccept_order()" function in legacy Dist_order.php.
     * What was 100+ lines of raw SQL and dual-db updates is now a clean mapped transaction.
     */
    public function accept(Request $request, DistOrder $distOrder)
    {
        if ($distOrder->status !== 'pending') {
            return back()->with('error', 'Only pending orders can be accepted.');
        }

        // We assume batches are locked in on the frontend during acceptance
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:dist_order_items,id',
            'items.*.batch_no' => 'required|string', // HO must assign a batch
            'items.*.approved_qty' => 'required|numeric|min:0.1',
            'items.*.free_qty' => 'nullable|numeric|min:0',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            DB::transaction(function () use ($distOrder, $validated, $request) {
                $totalTaxable = 0;
                $totalGst = 0;

                foreach ($validated['items'] as $itemData) {
                    $item = $distOrder->items()->find($itemData['id']);
                    
                    // Verify HO warehouse has enough stock for the approved batch!
                    $reqFreeQty = $itemData['free_qty'] ?? 0;
                    $requiredQty = $itemData['approved_qty'] + $reqFreeQty;

                    if (!$this->inventoryService->hasSufficientStock($item->product_id, $itemData['batch_no'], 'warehouse', 0, $requiredQty)) {
                        throw new \Exception("Insufficient stock in warehouse for Product {$item->product->product_name}, Batch {$itemData['batch_no']}. Required: {$requiredQty}.");
                    }

                    $rate = $itemData['rate'];
                    $discountPercent = $itemData['discount_percent'] ?? 0;
                    $taxableAmount = ($itemData['approved_qty'] * $rate) * (1 - ($discountPercent / 100));
                    $gstAmount = $taxableAmount * ($item->gst_percent / 100);

                    $item->update([
                        'batch_no' => $itemData['batch_no'],
                        'approved_qty' => $itemData['approved_qty'],
                        'free_qty' => $reqFreeQty,
                        'rate' => $rate,
                        'discount_percent' => $discountPercent,
                        'taxable_amount' => $taxableAmount,
                        'gst_amount' => $gstAmount,
                        'total_amount' => $taxableAmount + $gstAmount
                    ]);

                    $totalTaxable += $taxableAmount;
                    $totalGst += $gstAmount;
                }

                $totalAmount = $totalTaxable + $totalGst;
                $roundOff = round($totalAmount) - $totalAmount;

                // 2. Lock the Order Header Status
                $distOrder->update([
                    'status' => 'accepted',
                    'subtotal' => $totalTaxable,
                    'total_amount' => round($totalAmount + $roundOff, 2),
                    'round_off' => $roundOff,
                    'accepted_by' => $request->user()->id,
                    'accepted_at' => now()
                ]);

                // 4. Trigger the recursive Commission Service Engine!
                $this->commissionService->generateCommissionsForOrder($distOrder);
                
                // Track total commission generated directly onto the order
                $distOrder->update(['total_commission' => $distOrder->commissions()->sum('gross_commission')]);
            });

            return back()->with('success', 'Order accepted successfully. Batches allocated. Ready for Dispatch.');

        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Dispatch the order. HO stock --> Franchisee Stock via the unified InventoryService.
     * Replaces legacy accept_order tracking logic.
     */
    public function dispatchOrder(Request $request, DistOrder $distOrder)
    {
        if ($distOrder->status !== 'accepted') {
            return back()->with('error', 'Only accepted orders can be dispatched.');
        }

        $validated = $request->validate([
            'courier_name' => 'required|string|max:255',
            'tracking_number' => 'required|string|max:255',
            'tracking_link' => 'nullable|url',
            'dispatch_date' => 'required|date',
            'invoice_number' => 'nullable|string',
            'ebill_number' => 'nullable|string',
        ]);

        try {
            DB::transaction(function () use ($distOrder, $validated, $request) {
                // 1. Deduct from HO and Increment Franchisee via Unified Ledger!!
                foreach ($distOrder->items as $item) {
                    // This generates exactly two rows in inventory_ledgers (one OUT, one IN). IMMUTABLE.
                    $this->inventoryService->recordDispatch([
                        'product_id' => $item->product_id,
                        'batch_no' => $item->batch_no,
                        'franchisee_id' => $distOrder->franchisee_id,
                        'qty' => $item->approved_qty + $item->free_qty,
                        'rate' => $item->rate,
                        'order_id' => $distOrder->id,
                        'created_by' => $request->user()->id
                    ]);
                }

                // 2. Financial Ledger Entry: Debit the Franchisee for the total amount of this B2B Order
                $this->ledgerService->recordEntry(
                    ledgerable: $distOrder->franchisee,
                    transactionType: 'PURCHASE',
                    debit: $distOrder->total_amount,
                    credit: 0,
                    reference: $distOrder,
                    paymentMode: 'CREDIT',
                    narration: "B2B Stock Purchase - Invoice {$distOrder->invoice_number} / Order {$distOrder->order_number}"
                );

                // 3. Update Header
                $distOrder->update(array_merge($validated, [
                    'status' => 'dispatched',
                    'dispatched_by' => $request->user()->id,
                    'dispatched_at' => now(),
                ]));
                
                // Future Implementation: Send Email Notification via queue (legacy logic: dispatch@genericplus...)
            });

            return back()->with('success', 'Order dispatched successfully. Stock immediately transferred to Franchisee.');

        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reject(Request $request, DistOrder $distOrder)
    {
        if (in_array($distOrder->status, ['dispatched', 'delivered', 'cancelled'])) {
            return back()->with('error', 'Cannot reject an order in this status.');
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500'
        ]);

        // Reverse stock if it was already accepted and reserved?
        // Since we deduct on dispatch, we don't need to reverse stock ledger purely on pending/accepted state!
        // Reversing Commissions
        if ($distOrder->status === 'accepted') {
            DB::transaction(function () use ($distOrder) {
                // Wipe pending commissions
                $distOrder->commissions()->delete();
                $distOrder->update(['total_commission' => 0]);
            });
        }

        $distOrder->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['rejection_reason']
        ]);

        return back()->with('success', 'Order rejected.');
    }
}
