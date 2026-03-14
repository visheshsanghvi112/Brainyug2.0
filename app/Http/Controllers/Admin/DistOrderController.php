<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\B2bCart;
use App\Models\DistOrder;
use App\Models\DistOrderItem;
use App\Models\DistOrderPayment;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\CommissionService;
use App\Services\LedgerService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DistOrderController extends Controller
{
    private const ORDER_LOCK_TIMEOUT_MINUTES = 10;

    public function __construct(
        private InventoryService $inventoryService,
        private CommissionService $commissionService,
        private LedgerService $ledgerService
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $queue = $request->string('queue')->toString();

        $applyVisibilityScope = function ($query) use ($user) {
            $query->when($user->franchisee_id, fn ($q, $franchiseeId) => $q->where('franchisee_id', $franchiseeId));
        };

        $baseQuery = DistOrder::query();
        $applyVisibilityScope($baseQuery);

        $orders = DistOrder::with(['franchisee', 'user'])
            ->withCount([
                'payments as pending_payments_count' => fn ($q) => $q->where('status', 'pending'),
            ])
            ->tap($applyVisibilityScope)
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('order_number', 'like', "%{$search}%")
                       ->orWhereHas('franchisee', fn ($f) => $f->where('shop_name', 'like', "%{$search}%"));
                });
            })
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($queue === 'pending_orders', fn ($q) => $q->where('status', 'pending'))
            ->when($queue === 'pending_dispatch', fn ($q) => $q->where('status', 'accepted'))
            ->when($queue === 'payment_review', fn ($q) => $q->whereHas('payments', fn ($p) => $p->where('status', 'pending')))
            ->when($queue === 'open_work', fn ($q) => $q->whereIn('status', ['pending', 'accepted', 'dispatched']))
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'accepted' THEN 1 WHEN 'dispatched' THEN 2 ELSE 3 END")
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        $metrics = [
            'pending_orders' => (clone $baseQuery)->where('status', 'pending')->count(),
            'pending_dispatch' => (clone $baseQuery)->where('status', 'accepted')->count(),
            'payment_review' => (clone $baseQuery)->whereHas('payments', fn ($p) => $p->where('status', 'pending'))->count(),
            'open_work' => (clone $baseQuery)->whereIn('status', ['pending', 'accepted', 'dispatched'])->count(),
        ];

        return Inertia::render('Distribution/Orders/Index', [
            'orders' => $orders,
            'filters' => $request->only(['search', 'status', 'queue']),
            'metrics' => $metrics,
        ]);
    }

    public function show(Request $request, DistOrder $distOrder)
    {
        $this->ensureOrderVisibleToUser($request->user(), $distOrder);

        $orderLock = $this->acquireOrderViewLock($request->user(), $distOrder);

        $distOrder->load([
            'franchisee',
            'user',
            'items.product',
            'acceptedBy',
            'dispatchedBy',
            'payments.createdBy',
            'payments.confirmedBy',
            'payments.rejectedBy',
        ]);

        // Attach available batches and HO stock dynamically for the UI
        foreach ($distOrder->items as $item) {
            $item->available_batches = $this->inventoryService->getProductStockAtLocation($item->product_id, 'warehouse', 0);
        }

        return Inertia::render('Distribution/Orders/Show', [
            'order' => $distOrder,
            'orderLock' => $orderLock,
            'paymentSummary' => $this->paymentSummary($distOrder),
            'canReviewBills' => $this->canReviewBills($request->user()),
            'canReorderRejectedOrder' => $this->canReorderRejectedOrder($request->user(), $distOrder),
            'canSubmitPayment' => $this->canSubmitPayment($request->user(), $distOrder),
            'canManagePayments' => $this->canManagePayments($request->user()),
        ]);
    }

    public function picklistPdf(Request $request, DistOrder $distOrder)
    {
        $this->ensureOrderVisibleToUser($request->user(), $distOrder);

        $distOrder->load([
            'franchisee',
            'user',
            'items.product',
            'acceptedBy',
            'dispatchedBy',
        ]);

        $totals = [
            'requested_qty' => round((float) $distOrder->items->sum('request_qty'), 2),
            'approved_qty' => round((float) $distOrder->items->sum('approved_qty'), 2),
            'free_qty' => round((float) $distOrder->items->sum('free_qty'), 2),
            'line_count' => $distOrder->items->count(),
        ];

        $pdf = Pdf::loadView('documents.dist-order-picklist', [
            'order' => $distOrder,
            'totals' => $totals,
        ])->setPaper('a4', 'landscape');

        return $pdf->download($distOrder->order_number . '_picklist.pdf');
    }

    public function gstInvoicePdf(Request $request, DistOrder $distOrder)
    {
        $this->ensureOrderVisibleToUser($request->user(), $distOrder);

        $distOrder->load([
            'franchisee',
            'user',
            'items.product',
            'acceptedBy',
            'dispatchedBy',
        ]);

        $taxableTotal = 0.0;
        $gstTotal = 0.0;
        foreach ($distOrder->items as $line) {
            $taxableTotal += (float) $line->taxable_amount;
            $gstTotal += (float) $line->gst_amount;
        }

        $isIntraState = (float) $distOrder->cgst_amount > 0 || (float) $distOrder->sgst_amount > 0;
        $sgst = $isIntraState ? round($gstTotal / 2, 2) : 0.0;
        $cgst = $isIntraState ? round($gstTotal / 2, 2) : 0.0;
        $igst = $isIntraState ? 0.0 : round($gstTotal, 2);

        $summary = [
            'taxable_total' => round($taxableTotal, 2),
            'gst_total' => round($gstTotal, 2),
            'sgst_total' => $sgst,
            'cgst_total' => $cgst,
            'igst_total' => $igst,
            'round_off' => round((float) $distOrder->round_off, 2),
            'net_total' => round((float) $distOrder->total_amount, 2),
        ];

        $pdf = Pdf::loadView('documents.dist-order-gst-invoice', [
            'order' => $distOrder,
            'summary' => $summary,
        ])->setPaper('a4', 'portrait');

        return $pdf->download($distOrder->order_number . '_gst_invoice.pdf');
    }

    public function reorderToCart(Request $request, DistOrder $distOrder)
    {
        $user = $request->user();
        $this->ensureOrderVisibleToUser($user, $distOrder);

        if (!$this->canReorderRejectedOrder($user, $distOrder)) {
            abort(403, 'This order cannot be requeued to cart.');
        }

        $distOrder->loadMissing('items.product');

        DB::transaction(function () use ($distOrder, $user) {
            $franchiseeId = $user->getEffectiveFranchiseeId();
            $cart = B2bCart::firstOrCreate([
                'franchisee_id' => $franchiseeId,
                'user_id' => $user->id,
            ]);

            foreach ($distOrder->items as $line) {
                $product = Product::query()
                    ->visibleForFranchise()
                    ->whereKey($line->product_id)
                    ->first();

                if (!$product) {
                    continue;
                }

                $qty = (float) ($line->request_qty > 0 ? $line->request_qty : $line->approved_qty);
                if ($qty <= 0) {
                    continue;
                }

                $existing = $cart->items()->where('product_id', $line->product_id)->first();
                $targetQty = $existing ? round((float) $existing->qty + $qty, 2) : round($qty, 2);
                $rate = $product->franchiseRate();
                $freeQty = $this->calculateReorderFreeQty($targetQty);

                if ($existing) {
                    $existing->update([
                        'qty' => $targetQty,
                        'free_qty' => $freeQty,
                        'rate' => $rate,
                        'total_amount' => round($targetQty * $rate, 2),
                    ]);
                } else {
                    $cart->items()->create([
                        'product_id' => $line->product_id,
                        'qty' => $targetQty,
                        'free_qty' => $freeQty,
                        'rate' => $rate,
                        'total_amount' => round($targetQty * $rate, 2),
                    ]);
                }
            }

            $subtotal = round((float) $cart->items()->sum('total_amount'), 2);
            $cart->update([
                'subtotal' => $subtotal,
                'total_amount' => $subtotal,
            ]);
        });

        return redirect()->route('b2b.cart.index')
            ->with('success', 'Rejected order items were moved back to your cart. Review and submit again.');
    }

    public function unlock(Request $request, DistOrder $distOrder)
    {
        $user = $request->user();
        $this->ensureOrderVisibleToUser($user, $distOrder);

        DB::transaction(function () use ($user, $distOrder) {
            $lockedOrder = DistOrder::whereKey($distOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$this->shouldEnforceOrderLock($lockedOrder)) {
                return;
            }

            if (!$lockedOrder->locked_by) {
                return;
            }

            $canUnlock = (int) $lockedOrder->locked_by === (int) $user->id
                || $this->isOrderLockExpired($lockedOrder->locked_at)
                || $this->canForceUnlock($user);

            if (!$canUnlock) {
                abort(403, 'This order is locked by another user.');
            }

            $this->clearOrderLock($lockedOrder);
        });

        return back()->with('success', 'Order lock released.');
    }

    public function submitPayment(Request $request, DistOrder $distOrder)
    {
        $user = $request->user();

        if (!$user->franchisee_id) {
            abort(403, 'Only franchisee accounts can submit B2B payment details.');
        }

        $this->ensureOrderVisibleToUser($user, $distOrder);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_mode' => 'required|string|in:cash,bank,upi,cheque,neft,rtgs',
            'reference_no' => 'nullable|string|max:255',
            'payment_date' => 'required|date',
            'narration' => 'nullable|string|max:1000',
        ]);

        if (!in_array($distOrder->status, ['dispatched', 'delivered'], true)) {
            abort(422, 'Payments can only be submitted after the order is dispatched.');
        }

        DB::transaction(function () use ($distOrder, $user, $validated) {
            $lockedOrder = DistOrder::whereKey($distOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            $reservedAmount = (float) DistOrderPayment::query()
                ->where('dist_order_id', $lockedOrder->id)
                ->whereIn('status', ['pending', 'confirmed'])
                ->lockForUpdate()
                ->sum('amount');

            $availableToSubmit = round(max(0, (float) $lockedOrder->total_amount - $reservedAmount), 2);
            $requestedAmount = round((float) $validated['amount'], 2);

            if ($requestedAmount > $availableToSubmit) {
                abort(422, "Payment exceeds outstanding amount available for submission. Available amount is {$availableToSubmit}.");
            }

            DistOrderPayment::create([
                'dist_order_id' => $lockedOrder->id,
                'franchisee_id' => $lockedOrder->franchisee_id,
                'created_by' => $user->id,
                'amount' => $requestedAmount,
                'payment_mode' => $validated['payment_mode'],
                'reference_no' => $validated['reference_no'] ?? null,
                'payment_date' => $validated['payment_date'],
                'narration' => $validated['narration'] ?? null,
            ]);
        });

        return back()->with('success', 'Payment submitted for HO confirmation.');
    }

    public function confirmPayment(Request $request, DistOrder $distOrder, DistOrderPayment $distOrderPayment)
    {
        if (!$this->canManagePayments($request->user())) {
            abort(403);
        }

        $this->ensurePaymentBelongsToOrder($distOrder, $distOrderPayment);

        DB::transaction(function () use ($request, $distOrder, $distOrderPayment) {
            $lockedOrder = DistOrder::whereKey($distOrder->id)
                ->lockForUpdate()
                ->with('franchisee')
                ->firstOrFail();

            $payment = DistOrderPayment::whereKey($distOrderPayment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->status !== 'pending') {
                abort(422, 'Only pending payments can be confirmed.');
            }

            $ledger = $this->ledgerService->recordEntry(
                ledgerable: $lockedOrder->franchisee,
                transactionType: 'PAYMENT_RECEIVED',
                debit: 0,
                credit: (float) $payment->amount,
                reference: $payment,
                paymentMode: strtolower($payment->payment_mode),
                narration: $payment->narration
                    ? "B2B payment for Order {$lockedOrder->order_number}: {$payment->narration}"
                    : "B2B payment for Order {$lockedOrder->order_number}",
                transactionDate: $payment->payment_date,
            );

            $payment->update([
                'status' => 'confirmed',
                'financial_ledger_id' => $ledger->id,
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);
        });

        return back()->with('success', 'Payment confirmed and ledger updated.');
    }

    public function rejectPayment(Request $request, DistOrder $distOrder, DistOrderPayment $distOrderPayment)
    {
        if (!$this->canManagePayments($request->user())) {
            abort(403);
        }

        $this->ensurePaymentBelongsToOrder($distOrder, $distOrderPayment);

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        DB::transaction(function () use ($request, $distOrderPayment, $validated) {
            $payment = DistOrderPayment::whereKey($distOrderPayment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->status !== 'pending') {
                abort(422, 'Only pending payments can be rejected.');
            }

            $payment->update([
                'status' => 'rejected',
                'rejected_by' => $request->user()->id,
                'rejected_at' => now(),
                'rejection_reason' => $validated['rejection_reason'],
            ]);
        });

        return back()->with('success', 'Payment submission rejected.');
    }

    /**
     * Replaces the massive "ordereraccept_order()" function in legacy Dist_order.php.
     * What was 100+ lines of raw SQL and dual-db updates is now a clean mapped transaction.
     */
    public function accept(Request $request, DistOrder $distOrder)
    {
        $this->assertCanMutateOrder($request->user());

        // We assume batches are locked in on the frontend during acceptance
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:dist_order_items,id',
            'items.*.batch_no' => 'nullable|string',
            'items.*.approved_qty' => 'required|numeric|min:0',
            'items.*.free_qty' => 'nullable|numeric|min:0',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            DB::transaction(function () use ($distOrder, $validated, $request) {
                $lockedOrder = DistOrder::whereKey($distOrder->id)
                    ->lockForUpdate()
                    ->with(['items.product', 'franchisee'])
                    ->firstOrFail();

                if ($lockedOrder->status !== 'pending') {
                    throw new \Exception('Only pending orders can be accepted.');
                }

                $this->assertOrderLockOwnership($request->user(), $lockedOrder);

                $totalTaxable = 0;
                $totalGst = 0;
                $seenItemIds = [];
                $billableLines = 0;

                foreach ($validated['items'] as $itemData) {
                    if (in_array((int) $itemData['id'], $seenItemIds, true)) {
                        throw new \Exception('Duplicate order item payload detected. Please refresh and retry.');
                    }
                    $seenItemIds[] = (int) $itemData['id'];

                    $item = $lockedOrder->items->firstWhere('id', $itemData['id']);
                    if (!$item) {
                        throw new \Exception('One or more order items are invalid for this order.');
                    }

                    $approvedQty = (float) $itemData['approved_qty'];
                    $freeQty = (float) ($itemData['free_qty'] ?? 0);
                    $batchNo = isset($itemData['batch_no']) ? trim((string) $itemData['batch_no']) : '';
                    $requiredQty = $approvedQty + $freeQty;

                    if ($approvedQty < 0 || $freeQty < 0) {
                        throw new \Exception("Quantity cannot be negative for Product {$item->product->product_name}.");
                    }

                    if ($requiredQty > 0) {
                        $billableLines++;
                    }

                    if ($requiredQty > 0 && $batchNo === '') {
                        throw new \Exception("Batch is required for Product {$item->product->product_name}.");
                    }
                    
                    // Verify HO warehouse has enough stock for the approved batch!
                    if ($requiredQty > 0 && !$this->inventoryService->hasSufficientStock($item->product_id, $batchNo, 'warehouse', 0, $requiredQty)) {
                        throw new \Exception("Insufficient stock in warehouse for Product {$item->product->product_name}, Batch {$batchNo}. Required: {$requiredQty}.");
                    }

                    $rate = (float) $itemData['rate'];
                    $discountPercent = (float) ($itemData['discount_percent'] ?? 0);
                    $taxableAmount = ($approvedQty * $rate) * (1 - ($discountPercent / 100));
                    $gstAmount = $taxableAmount * ($item->gst_percent / 100);

                    $item->update([
                        'batch_no' => $requiredQty > 0 ? $batchNo : null,
                        'approved_qty' => $approvedQty,
                        'free_qty' => $freeQty,
                        'rate' => $rate,
                        'discount_percent' => $discountPercent,
                        'taxable_amount' => $taxableAmount,
                        'gst_amount' => $gstAmount,
                        'total_amount' => $taxableAmount + $gstAmount
                    ]);

                    $totalTaxable += $taxableAmount;
                    $totalGst += $gstAmount;
                }

                if ($billableLines === 0) {
                    throw new \Exception('At least one line must have approved or free quantity before accepting the order.');
                }

                $totalAmount = $totalTaxable + $totalGst;
                $roundOff = round($totalAmount) - $totalAmount;

                // 2. Lock the Order Header Status
                $lockedOrder->update([
                    'status' => 'accepted',
                    'subtotal' => $totalTaxable,
                    'total_amount' => round($totalAmount + $roundOff, 2),
                    'round_off' => $roundOff,
                    'accepted_by' => $request->user()->id,
                    'accepted_at' => now()
                ]);

                // 4. Trigger the recursive Commission Service Engine!
                $this->commissionService->generateCommissionsForOrder($lockedOrder);
                
                // Track total commission generated directly onto the order
                $lockedOrder->update(['total_commission' => $lockedOrder->commissions()->sum('gross_commission')]);

                $this->clearOrderLock($lockedOrder);
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
        $this->assertCanMutateOrder($request->user());

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
                $lockedOrder = DistOrder::whereKey($distOrder->id)
                    ->lockForUpdate()
                    ->with(['items', 'franchisee'])
                    ->firstOrFail();

                if ($lockedOrder->status !== 'accepted') {
                    throw new \Exception('Only accepted orders can be dispatched.');
                }

                $this->assertOrderLockOwnership($request->user(), $lockedOrder);

                // 1. Deduct from HO and Increment Franchisee via Unified Ledger!!
                foreach ($lockedOrder->items as $item) {
                    // This generates exactly two rows in inventory_ledgers (one OUT, one IN). IMMUTABLE.
                    $this->inventoryService->recordDispatch([
                        'product_id' => $item->product_id,
                        'batch_no' => $item->batch_no,
                        'franchisee_id' => $lockedOrder->franchisee_id,
                        'qty' => $item->approved_qty + $item->free_qty,
                        'rate' => $item->rate,
                        'order_id' => $lockedOrder->id,
                        'created_by' => $request->user()->id
                    ]);
                }

                // 2. Financial Ledger Entry: Debit the Franchisee for the total amount of this B2B Order
                $this->ledgerService->recordEntry(
                    ledgerable: $lockedOrder->franchisee,
                    transactionType: 'PURCHASE',
                    debit: $lockedOrder->total_amount,
                    credit: 0,
                    reference: $lockedOrder,
                    paymentMode: 'CREDIT',
                    narration: "B2B Stock Purchase - Invoice {$lockedOrder->invoice_number} / Order {$lockedOrder->order_number}"
                );

                // 3. Update Header
                $lockedOrder->update(array_merge($validated, [
                    'status' => 'dispatched',
                    'dispatched_by' => $request->user()->id,
                    'dispatched_at' => now(),
                ]));

                $this->clearOrderLock($lockedOrder);
                
                // Future Implementation: Send Email Notification via queue (legacy logic: dispatch@genericplus...)
            });

            return back()->with('success', 'Order dispatched successfully. Stock immediately transferred to Franchisee.');

        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reject(Request $request, DistOrder $distOrder)
    {
        $this->assertCanMutateOrder($request->user());

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500'
        ]);

        try {
            DB::transaction(function () use ($distOrder, $validated, $request) {
                $lockedOrder = DistOrder::whereKey($distOrder->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (in_array($lockedOrder->status, ['dispatched', 'delivered', 'cancelled'], true)) {
                    throw new \Exception('Cannot reject an order in this status.');
                }

                $this->assertOrderLockOwnership($request->user(), $lockedOrder);

                // Since stock moves only on dispatch, accepted rejection requires only commission cleanup.
                if ($lockedOrder->status === 'accepted') {
                    $lockedOrder->commissions()->delete();
                    $lockedOrder->update(['total_commission' => 0]);
                }

                $lockedOrder->update([
                    'status' => 'rejected',
                    'rejection_reason' => $validated['rejection_reason']
                ]);

                $this->clearOrderLock($lockedOrder);
            });
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Order rejected.');
    }

    private function ensureOrderVisibleToUser($user, DistOrder $distOrder): void
    {
        if ($user->franchisee_id && $distOrder->franchisee_id !== $user->franchisee_id) {
            abort(404);
        }
    }

    private function ensurePaymentBelongsToOrder(DistOrder $distOrder, DistOrderPayment $distOrderPayment): void
    {
        if ($distOrderPayment->dist_order_id !== $distOrder->id) {
            abort(404);
        }
    }

    private function canSubmitPayment($user, DistOrder $distOrder): bool
    {
        return (bool) $user->franchisee_id
            && $distOrder->franchisee_id === $user->franchisee_id
            && in_array($distOrder->status, ['dispatched', 'delivered'], true);
    }

    private function canManagePayments($user): bool
    {
        return !$user->franchisee_id;
    }

    private function canReorderRejectedOrder($user, DistOrder $distOrder): bool
    {
        if (!$user->franchisee_id) {
            return false;
        }

        return $distOrder->franchisee_id === $user->franchisee_id
            && in_array($distOrder->status, ['rejected', 'cancelled'], true);
    }

    private function canReviewBills($user): bool
    {
        return !$user->franchisee_id;
    }

    private function assertCanMutateOrder($user): void
    {
        if ($user->franchisee_id) {
            abort(403, 'Franchisee users cannot modify distribution orders.');
        }
    }

    private function paymentSummary(DistOrder $distOrder): array
    {
        $payments = $distOrder->relationLoaded('payments')
            ? $distOrder->payments
            : $distOrder->payments()->get();

        $confirmed = round((float) $payments->where('status', 'confirmed')->sum('amount'), 2);
        $pending = round((float) $payments->where('status', 'pending')->sum('amount'), 2);
        $rejected = round((float) $payments->where('status', 'rejected')->sum('amount'), 2);
        $gross = round((float) $distOrder->total_amount, 2);

        return [
            'gross' => $gross,
            'confirmed' => $confirmed,
            'pending' => $pending,
            'rejected' => $rejected,
            'outstanding' => round(max(0, $gross - $confirmed), 2),
            'available_to_submit' => round(max(0, $gross - $confirmed - $pending), 2),
        ];
    }

    private function acquireOrderViewLock($user, DistOrder $distOrder): array
    {
        if (!$this->shouldEnforceOrderLock($distOrder)) {
            return [
                'enabled' => false,
                'is_blocked' => false,
                'is_owner' => false,
                'locked_by_name' => null,
                'locked_at' => null,
                'timeout_minutes' => self::ORDER_LOCK_TIMEOUT_MINUTES,
                'can_force_unlock' => false,
            ];
        }

        return DB::transaction(function () use ($user, $distOrder) {
            $lockedOrder = DistOrder::whereKey($distOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            $ownerId = (int) ($lockedOrder->locked_by ?? 0);
            $isExpired = $this->isOrderLockExpired($lockedOrder->locked_at);

            if ($ownerId > 0 && $ownerId !== (int) $user->id && !$isExpired) {
                $lockedByName = User::query()->whereKey($ownerId)->value('name') ?? 'Unknown User';

                return [
                    'enabled' => true,
                    'is_blocked' => true,
                    'is_owner' => false,
                    'locked_by_name' => $lockedByName,
                    'locked_at' => optional($lockedOrder->locked_at)->toDateTimeString(),
                    'timeout_minutes' => self::ORDER_LOCK_TIMEOUT_MINUTES,
                    'can_force_unlock' => $this->canForceUnlock($user),
                ];
            }

            $lockedOrder->update([
                'locked_by' => $user->id,
                'locked_at' => now(),
            ]);

            return [
                'enabled' => true,
                'is_blocked' => false,
                'is_owner' => true,
                'locked_by_name' => $user->name,
                'locked_at' => now()->toDateTimeString(),
                'timeout_minutes' => self::ORDER_LOCK_TIMEOUT_MINUTES,
                'can_force_unlock' => $this->canForceUnlock($user),
            ];
        });
    }

    private function shouldEnforceOrderLock(DistOrder $distOrder): bool
    {
        return in_array($distOrder->status, ['pending', 'accepted'], true);
    }

    private function isOrderLockExpired($lockedAt): bool
    {
        if (!$lockedAt) {
            return true;
        }

        return now()->diffInMinutes($lockedAt) >= self::ORDER_LOCK_TIMEOUT_MINUTES;
    }

    private function canForceUnlock($user): bool
    {
        return !$user->franchisee_id;
    }

    private function assertOrderLockOwnership($user, DistOrder $distOrder): void
    {
        if (!$this->shouldEnforceOrderLock($distOrder)) {
            return;
        }

        $ownerId = (int) ($distOrder->locked_by ?? 0);
        if ($ownerId === 0 || $ownerId === (int) $user->id || $this->isOrderLockExpired($distOrder->locked_at)) {
            return;
        }

        $lockedByName = User::query()->whereKey($ownerId)->value('name') ?? 'another user';
        throw new \Exception("Order is currently being edited by {$lockedByName}. Please try after lock timeout or force unlock.");
    }

    private function clearOrderLock(DistOrder $distOrder): void
    {
        $distOrder->update([
            'locked_by' => null,
            'locked_at' => null,
        ]);
    }

    private function calculateReorderFreeQty(float $qty): float
    {
        if ($qty < 10) {
            return 0.0;
        }

        return (float) floor($qty / 10);
    }
}
