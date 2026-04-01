<?php

namespace App\Services;

use App\Models\DistOrder;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class DistOrderDispatchService
{
    private const ORDER_LOCK_TIMEOUT_MINUTES = 10;

    public function __construct(
        private InventoryService $inventoryService,
        private LedgerService $ledgerService,
        private DistOrderWorkflowService $distOrderWorkflowService,
        private CommissionLifecycleService $commissionLifecycleService
    ) {}

    public function dispatch(DistOrder $distOrder, User $actor, array $payload): DistOrder
    {
        return DB::transaction(function () use ($distOrder, $actor, $payload) {
            $lockedOrder = DistOrder::whereKey($distOrder->id)
                ->lockForUpdate()
                ->with(['items.product', 'franchisee'])
                ->firstOrFail();

            if ($lockedOrder->status !== 'allocated') {
                throw new DomainException('Only allocated orders can be dispatched.');
            }

            $this->assertLockOwnership($actor, $lockedOrder);

            $dispatchableLines = 0;

            foreach ($lockedOrder->items as $item) {
                $dispatchQty = round((float) $item->approved_qty + (float) $item->free_qty, 2);
                if ($dispatchQty <= 0) {
                    continue;
                }

                if (!$item->batch_no) {
                    throw new DomainException("Allocated batch missing for Product {$item->product->product_name}.");
                }

                $dispatchableLines++;

                $this->inventoryService->recordDispatch([
                    'product_id' => $item->product_id,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                    'mrp' => $item->mrp,
                    'franchisee_id' => $lockedOrder->franchisee_id,
                    'qty' => $dispatchQty,
                    'rate' => $item->rate,
                    'order_id' => $lockedOrder->id,
                    'created_by' => $actor->id,
                ]);
            }

            if ($dispatchableLines === 0) {
                throw new DomainException('At least one approved line must exist before dispatch.');
            }

            $invoiceNumber = trim((string) ($payload['invoice_number'] ?? ''));
            $invoiceReference = $invoiceNumber !== '' ? $invoiceNumber : $lockedOrder->order_number;

            $this->ledgerService->recordEntry(
                ledgerable: $lockedOrder->franchisee,
                transactionType: 'PURCHASE',
                debit: (float) $lockedOrder->total_amount,
                credit: 0,
                reference: $lockedOrder,
                paymentMode: 'CREDIT',
                narration: "B2B Stock Purchase - Invoice {$invoiceReference} / Order {$lockedOrder->order_number}",
                transactionDate: $payload['dispatch_date'] ?? null
            );

            $this->distOrderWorkflowService->transition(
                $lockedOrder,
                'dispatched',
                $actor,
                array_merge($payload, [
                    'dispatched_by' => $actor->id,
                    'dispatched_at' => now(),
                    'locked_by' => null,
                    'locked_at' => null,
                ]),
                'Order dispatched from HO warehouse to franchisee.',
                [
                    'courier_name' => $payload['courier_name'],
                    'tracking_number' => $payload['tracking_number'],
                    'invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : null,
                    'dispatchable_line_count' => $dispatchableLines,
                ]
            );

            $this->commissionLifecycleService->generateForDispatch($lockedOrder);

            return $lockedOrder->fresh(['items.product', 'franchisee']);
        });
    }

    private function assertLockOwnership(User $actor, DistOrder $distOrder): void
    {
        $ownerId = (int) ($distOrder->locked_by ?? 0);
        if ($ownerId === 0 || $ownerId === (int) $actor->id || $this->isOrderLockExpired($distOrder->locked_at)) {
            return;
        }

        $lockedByName = User::query()->whereKey($ownerId)->value('name') ?? 'another user';
        throw new DomainException("Order is currently being edited by {$lockedByName}. Please try after lock timeout or force unlock.");
    }

    private function isOrderLockExpired($lockedAt): bool
    {
        if (!$lockedAt) {
            return true;
        }

        return now()->diffInMinutes($lockedAt) >= self::ORDER_LOCK_TIMEOUT_MINUTES;
    }
}
