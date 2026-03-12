<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PurchaseReturnController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService
    ) {}

    public function index(Request $request)
    {
        $returns = PurchaseReturn::with(['supplier', 'purchaseInvoice', 'createdBy'])
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('return_number', 'like', "%{$search}%")
                       ->orWhereHas('supplier', fn($s) => $s->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->supplier_id, fn($q, $s) => $q->where('supplier_id', $s))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Procurement/PurchaseReturns/Index', [
            'returns' => $returns,
            'filters' => $request->only(['search', 'status', 'supplier_id']),
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Procurement/PurchaseReturns/CreateEdit', [
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name']),
            'invoices' => PurchaseInvoice::approved()->latest()->take(50)->get(['id', 'invoice_number', 'supplier_id']),
            'products' => Product::where('is_active', true)->orderBy('product_name')->get(['id', 'product_name', 'sku']),
            'financialYear' => PurchaseInvoice::currentFinancialYear(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'purchase_invoice_id' => 'nullable|exists:purchase_invoices,id',
            'return_date' => 'required|date',
            'reason' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.batch_no' => 'required|string|max:50',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.gst_percent' => 'required|numeric|min:0',
            'items.*.reason' => 'nullable|string',
        ]);

        DB::transaction(function () use ($validated, $request) {
            // Generate return number
            $lastReturn = PurchaseReturn::where('financial_year', PurchaseInvoice::currentFinancialYear())
                ->orderByDesc('id')
                ->first();
            $nextNum = $lastReturn ? ((int) substr($lastReturn->return_number, -4)) + 1 : 1;
            $returnNumber = 'PR-' . PurchaseInvoice::currentFinancialYear() . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

            $subtotal = 0;
            $totalSgst = 0;
            $totalCgst = 0;
            $totalIgst = 0;
            $itemsData = [];

            // We assume intra-state if no invoice linked, or we could fetch supplier state vs HO state.
            // For simplicity, if linked to invoice, use its tax type, else default intra.
            $taxType = 'intra_state';
            if (!empty($validated['purchase_invoice_id'])) {
                $invoice = PurchaseInvoice::find($validated['purchase_invoice_id']);
                if ($invoice) {
                    $taxType = $invoice->tax_type;
                }
            }

            foreach ($validated['items'] as $item) {
                $qty = $item['qty'];
                $rate = $item['rate'];
                $gstPct = $item['gst_percent'];

                $taxable = $qty * $rate;
                $gstAmt = $taxable * ($gstPct / 100);

                if ($taxType === 'intra_state') {
                    $totalSgst += $gstAmt / 2;
                    $totalCgst += $gstAmt / 2;
                } else {
                    $totalIgst += $gstAmt;
                }

                $subtotal += $taxable;
                $lineTotal = $taxable + $gstAmt;

                // Validate stock availability BEFORE allowing return
                if (!$this->inventoryService->hasSufficientStock(
                    $item['product_id'], $item['batch_no'], 'warehouse', 0, $qty
                )) {
                    throw new \Exception("Insufficient stock in warehouse for product ID {$item['product_id']}, batch {$item['batch_no']}. Cannot return {$qty}.");
                }

                $itemsData[] = array_merge($item, [
                    'gst_amount' => round($gstAmt, 2),
                    'total_amount' => round($lineTotal, 2),
                ]);
            }

            $totalAmount = $subtotal + $totalSgst + $totalCgst + $totalIgst;

            $purchaseReturn = PurchaseReturn::create([
                'return_number' => $returnNumber,
                'supplier_id' => $validated['supplier_id'],
                'purchase_invoice_id' => $validated['purchase_invoice_id'] ?? null,
                'return_date' => $validated['return_date'],
                'financial_year' => PurchaseInvoice::currentFinancialYear(),
                'subtotal' => round($subtotal, 2),
                'sgst_amount' => round($totalSgst, 2),
                'cgst_amount' => round($totalCgst, 2),
                'igst_amount' => round($totalIgst, 2),
                'total_amount' => round($totalAmount, 2),
                'status' => 'draft',
                'reason' => $validated['reason'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($itemsData as $item) {
                $purchaseReturn->items()->create($item);
            }
        });

        return redirect()->route('admin.purchase-returns.index')
            ->with('success', 'Purchase return created as draft.');
    }

    public function show(PurchaseReturn $purchaseReturn)
    {
        $purchaseReturn->load(['supplier', 'purchaseInvoice', 'items.product', 'createdBy', 'approvedBy']);

        return Inertia::render('Procurement/PurchaseReturns/Show', [
            'purchaseReturn' => $purchaseReturn,
        ]);
    }

    /**
     * APPROVE a purchase return → auto-create inventory ledger entries (DEDUCT STOCK).
     */
    public function approve(Request $request, PurchaseReturn $purchaseReturn)
    {
        if ($purchaseReturn->status !== 'draft') {
            return back()->with('error', 'Only draft returns can be approved.');
        }

        try {
            DB::transaction(function () use ($purchaseReturn, $request) {
                $purchaseReturn->update([
                    'status' => 'approved',
                    'approved_by' => $request->user()->id,
                ]);

                // Create inventory ledger entries for each line item
                foreach ($purchaseReturn->items as $item) {
                    $this->inventoryService->recordPurchaseReturn([
                        'product_id' => $item->product_id,
                        'batch_no' => $item->batch_no,
                        'expiry_date' => $item->expiry_date,
                        'qty' => $item->qty,
                        'rate' => $item->rate,
                        'reference_id' => $purchaseReturn->id,
                        'created_by' => $request->user()->id,
                    ]);
                }
            });
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Return approved. Stock deducted from warehouse.');
    }

    public function cancel(PurchaseReturn $purchaseReturn)
    {
        if ($purchaseReturn->status === 'cancelled') {
            return back()->with('error', 'Return is already cancelled.');
        }

        if ($purchaseReturn->status === 'approved') {
            return back()->with('error', 'Cannot cancel an approved return directly. Reverse it manually.');
        }

        $purchaseReturn->update(['status' => 'cancelled']);
        return back()->with('success', 'Return cancelled.');
    }
}
