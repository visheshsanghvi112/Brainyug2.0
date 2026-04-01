<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\B2bCart;
use App\Models\DistOrder;
use App\Models\DistOrderPayment;
use App\Models\Product;
use App\Models\User;
use App\Services\DistOrderDispatchService;
use App\Services\DistOrderAllocationService;
use App\Services\DistOrderPaymentService;
use App\Services\DistOrderReviewService;
use App\Services\DistOrderWorkflowService;
use App\Services\InventoryService;
use App\Services\ReportExportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DistOrderController extends Controller
{
    private const ORDER_LOCK_TIMEOUT_MINUTES = 10;

    public function __construct(
        private InventoryService $inventoryService,
        private DistOrderWorkflowService $distOrderWorkflowService,
        private DistOrderReviewService $distOrderReviewService,
        private DistOrderAllocationService $distOrderAllocationService,
        private DistOrderDispatchService $distOrderDispatchService,
        private DistOrderPaymentService $distOrderPaymentService,
        private ReportExportService $reportExportService
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $baseQuery = DistOrder::query();
        $this->applyOrderVisibilityScope($baseQuery, $user);

        $orders = $this->buildIndexQuery($request, $user)
            ->withCount([
                'payments as pending_payments_count' => fn ($q) => $q->where('status', 'pending'),
            ])
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'accepted' THEN 1 WHEN 'allocated' THEN 2 WHEN 'dispatched' THEN 3 ELSE 4 END")
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        $metrics = [
            'pending_orders' => (clone $baseQuery)->where('status', 'pending')->count(),
            'pending_allocation' => (clone $baseQuery)->where('status', 'accepted')->count(),
            'pending_dispatch' => (clone $baseQuery)->where('status', 'allocated')->count(),
            'payment_review' => (clone $baseQuery)->whereHas('payments', fn ($p) => $p->where('status', 'pending'))->count(),
            'open_work' => (clone $baseQuery)->whereIn('status', ['pending', 'accepted', 'allocated', 'dispatched'])->count(),
        ];

        return Inertia::render('Distribution/Orders/Index', [
            'orders' => $orders,
            'filters' => $request->only(['search', 'status', 'queue']),
            'metrics' => $metrics,
        ]);
    }

    public function export(Request $request)
    {
        $orders = $this->buildIndexQuery($request, $request->user())
            ->with(['franchisee', 'user', 'items', 'payments'])
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'accepted' THEN 1 WHEN 'allocated' THEN 2 WHEN 'dispatched' THEN 3 ELSE 4 END")
            ->latest('id')
            ->get();

        $headers = [
            'Order No',
            'Date',
            'Franchisee',
            'Raised By',
            'Status',
            'Items',
            'Requested Qty',
            'Approved Qty',
            'Free Qty',
            'Pending Payments',
            'Outstanding',
            'Total Amount',
        ];

        $rows = $orders->map(function (DistOrder $order) {
            $payments = $order->payments;
            $items = $order->items;
            $confirmedPayments = round((float) $payments->where('status', 'confirmed')->sum('amount'), 2);

            return [
                $order->order_number,
                optional($order->created_at)?->format('Y-m-d H:i'),
                $order->franchisee?->shop_name ?? '',
                $order->user?->name ?? '',
                ucfirst((string) $order->status),
                $items->count(),
                round((float) $items->sum('request_qty'), 2),
                round((float) $items->sum('approved_qty'), 2),
                round((float) $items->sum('free_qty'), 2),
                $payments->where('status', 'pending')->count(),
                round(max(0, (float) $order->total_amount - $confirmedPayments), 2),
                round((float) $order->total_amount, 2),
            ];
        })->all();

        $summary = [
            'Orders' => $orders->count(),
            'Pending' => $orders->where('status', 'pending')->count(),
            'Allocated' => $orders->where('status', 'allocated')->count(),
            'Dispatched' => $orders->where('status', 'dispatched')->count(),
            'Pending Payment Review' => $orders->filter(
                fn (DistOrder $order) => $order->payments->where('status', 'pending')->isNotEmpty()
            )->count(),
            'Total Order Value' => round((float) $orders->sum('total_amount'), 2),
        ];

        $format = strtolower((string) $request->input('format', 'csv'));

        if ($format === 'excel') {
            return $this->reportExportService->downloadExcel(
                fileBase: 'distribution_orders',
                sheetTitle: 'Distribution Orders',
                headers: $headers,
                rows: $rows,
                meta: $summary,
            );
        }

        if ($format === 'pdf') {
            return $this->reportExportService->downloadPdf(
                fileBase: 'distribution_orders',
                title: 'Distribution Orders',
                headers: $headers,
                rows: $rows,
                meta: $summary,
            );
        }

        $filename = 'distribution_orders_' . date('Y-m-d_H-i-s') . '.csv';
        $responseHeaders = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$filename",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($rows, $headers) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $headers);

            foreach ($rows as $row) {
                fputcsv($file, $row);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $responseHeaders);
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
            'statusLogs.actor',
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
            'workflowLabels' => [
                'current' => $this->distOrderWorkflowService->labelFor((string) $distOrder->status),
                'labels' => collect([
                    'pending',
                    'accepted',
                    'allocated',
                    'dispatched',
                    'delivered',
                    'rejected',
                    'cancelled',
                ])->mapWithKeys(fn (string $status) => [
                    $status => $this->distOrderWorkflowService->labelFor($status),
                ])->all(),
                'availableTransitions' => collect($this->distOrderWorkflowService->allowedTransitions((string) $distOrder->status))
                    ->map(fn (string $status) => [
                        'status' => $status,
                        'label' => $this->distOrderWorkflowService->labelFor($status),
                    ])
                    ->values(),
            ],
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

        $franchiseeId = $user->getEffectiveFranchiseeId();

        if (!$franchiseeId) {
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

        try {
            $this->distOrderPaymentService->submit($distOrder, $user, $validated);
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return back()->with('success', 'Payment submitted for HO confirmation.');
    }

    public function confirmPayment(Request $request, DistOrder $distOrder, DistOrderPayment $distOrderPayment)
    {
        if (!$this->canManagePayments($request->user())) {
            abort(403);
        }

        $this->ensurePaymentBelongsToOrder($distOrder, $distOrderPayment);

        try {
            $this->distOrderPaymentService->confirm($distOrder, $distOrderPayment, $request->user());
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

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

        try {
            $this->distOrderPaymentService->reject($distOrder, $distOrderPayment, $request->user(), $validated['rejection_reason']);
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return back()->with('success', 'Payment submission rejected.');
    }

    /**
     * Replaces the massive "ordereraccept_order()" function in legacy Dist_order.php.
     * Commercial approval happens here. Warehouse batch allocation is a separate step.
     */
    public function accept(Request $request, DistOrder $distOrder)
    {
        $this->assertCanMutateOrder($request->user());

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:dist_order_items,id',
            'items.*.approved_qty' => 'required|numeric|min:0',
            'items.*.free_qty' => 'nullable|numeric|min:0',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            $this->distOrderReviewService->approve($distOrder, $request->user(), $validated['items']);

            return back()->with('success', 'Order commercially approved. Continue to warehouse allocation.');

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
            $this->distOrderDispatchService->dispatch($distOrder, $request->user(), $validated);

            return back()->with('success', 'Order dispatched successfully. Stock immediately transferred to Franchisee.');

        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function allocate(Request $request, DistOrder $distOrder)
    {
        $this->assertCanMutateOrder($request->user());

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:dist_order_items,id',
            'items.*.batch_no' => 'nullable|string|max:50',
        ]);

        try {
            $this->distOrderAllocationService->allocate($distOrder, $request->user(), $validated['items']);

            return back()->with('success', 'Batch allocation locked. The order is now ready for dispatch.');
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
            $this->distOrderReviewService->reject($distOrder, $request->user(), $validated['rejection_reason']);
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Order rejected.');
    }

    private function ensureOrderVisibleToUser($user, DistOrder $distOrder): void
    {
        $franchiseeId = $user->getEffectiveFranchiseeId();

        if ($franchiseeId && $distOrder->franchisee_id !== $franchiseeId) {
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
        $franchiseeId = $user->getEffectiveFranchiseeId();

        return (bool) $franchiseeId
            && $distOrder->franchisee_id === $franchiseeId
            && in_array($distOrder->status, ['dispatched', 'delivered'], true);
    }

    private function canManagePayments($user): bool
    {
        return !$user->franchisee_id;
    }

    private function canReorderRejectedOrder($user, DistOrder $distOrder): bool
    {
        $franchiseeId = $user->getEffectiveFranchiseeId();

        if (!$franchiseeId) {
            return false;
        }

        return $distOrder->franchisee_id === $franchiseeId
            && in_array($distOrder->status, ['rejected', 'cancelled'], true);
    }

    private function canReviewBills($user): bool
    {
        return !$user->franchisee_id;
    }

    private function assertCanMutateOrder($user): void
    {
        if ($user->getEffectiveFranchiseeId()) {
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
        return in_array($distOrder->status, ['pending', 'accepted', 'allocated'], true);
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
        return !$user->getEffectiveFranchiseeId();
    }

    private function clearOrderLock(DistOrder $distOrder): void
    {
        $distOrder->update([
            'locked_by' => null,
            'locked_at' => null,
        ]);
    }

    private function buildIndexQuery(Request $request, $user)
    {
        $queue = $request->string('queue')->toString();

        return DistOrder::with(['franchisee', 'user'])
            ->tap(fn ($query) => $this->applyOrderVisibilityScope($query, $user))
            ->when($request->search, function ($query, $search) {
                $query->where(function ($searchQuery) use ($search) {
                    $searchQuery->where('order_number', 'like', "%{$search}%")
                        ->orWhereHas('franchisee', fn ($franchiseeQuery) => $franchiseeQuery->where('shop_name', 'like', "%{$search}%"));
                });
            })
            ->when($request->status, fn ($query, $status) => $query->where('status', $status))
            ->when($queue === 'pending_orders', fn ($query) => $query->where('status', 'pending'))
            ->when($queue === 'pending_allocation', fn ($query) => $query->where('status', 'accepted'))
            ->when($queue === 'pending_dispatch', fn ($query) => $query->where('status', 'allocated'))
            ->when($queue === 'payment_review', fn ($query) => $query->whereHas('payments', fn ($paymentQuery) => $paymentQuery->where('status', 'pending')))
            ->when($queue === 'open_work', fn ($query) => $query->whereIn('status', ['pending', 'accepted', 'allocated', 'dispatched']));
    }

    private function applyOrderVisibilityScope($query, $user): void
    {
        $franchiseeId = $user->getEffectiveFranchiseeId();

        $query->when($franchiseeId, fn ($scopedQuery, $effectiveFranchiseeId) => $scopedQuery->where('franchisee_id', $effectiveFranchiseeId));
    }

    private function calculateReorderFreeQty(float $qty): float
    {
        if ($qty < 10) {
            return 0.0;
        }

        return (float) floor($qty / 10);
    }
}
