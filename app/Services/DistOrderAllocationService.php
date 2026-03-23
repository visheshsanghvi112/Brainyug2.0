<?php

namespace App\Services;

use App\Models\DistOrder;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class DistOrderAllocationService
{
    private const ORDER_LOCK_TIMEOUT_MINUTES = 10;

    public function __construct(
        private InventoryService $inventoryService,
        private DistOrderWorkflowService $distOrderWorkflowService
    ) {}

    public function allocate(DistOrder $distOrder, User $actor, array $itemsPayload): DistOrder
    {
        return DB::transaction(function () use ($distOrder, $actor, $itemsPayload) {
            $lockedOrder = DistOrder::whereKey($distOrder->id)
                ->lockForUpdate()
                ->with(['items.product'])
                ->firstOrFail();

            if ($lockedOrder->status !== 'accepted') {
                throw new DomainException('Only approved orders can move into allocation.');
            }

            $this->assertLockOwnership($actor, $lockedOrder);

            $seenItemIds = [];
            $allocatedLines = 0;

            foreach ($itemsPayload as $itemData) {
                $itemId = (int) $itemData['id'];

                if (in_array($itemId, $seenItemIds, true)) {
                    throw new DomainException('Duplicate allocation payload detected. Please refresh and retry.');
                }
                $seenItemIds[] = $itemId;

                $item = $lockedOrder->items->firstWhere('id', $itemId);
                if (!$item) {
                    throw new DomainException('One or more order items are invalid for this order.');
                }

                $requiredQty = round((float) $item->approved_qty + (float) $item->free_qty, 2);
                $batchNo = trim((string) ($itemData['batch_no'] ?? ''));

                if ($requiredQty <= 0) {
                    $item->update([
                        'batch_no' => null,
                    ]);
                    continue;
                }

                if ($batchNo === '') {
                    throw new DomainException("Batch is required for Product {$item->product->product_name}.");
                }

                $batch = $this->inventoryService
                    ->getProductStockAtLocation((int) $item->product_id, 'warehouse', 0)
                    ->firstWhere('batch_no', $batchNo);

                if (!$batch) {
                    throw new DomainException("Selected batch {$batchNo} is not currently available for Product {$item->product->product_name}.");
                }

                if ((float) $batch->stock < $requiredQty) {
                    throw new DomainException("Insufficient stock in warehouse for Product {$item->product->product_name}, Batch {$batchNo}. Required: {$requiredQty}.");
                }

                $allocatedLines++;

                $item->update([
                    'batch_no' => $batchNo,
                    'expiry_date' => $batch->expiry_date ?? $item->expiry_date,
                    'mrp' => $batch->mrp ?? $item->mrp,
                ]);
            }

            if ($allocatedLines === 0) {
                throw new DomainException('At least one approved line must be batch allocated before dispatch.');
            }

            $this->distOrderWorkflowService->transition(
                $lockedOrder,
                'allocated',
                $actor,
                [
                    'locked_by' => null,
                    'locked_at' => null,
                ],
                'Order batch allocation finalized and ready for dispatch desk.',
                [
                    'allocated_line_count' => $allocatedLines,
                    'line_count' => count($itemsPayload),
                ]
            );

            return $lockedOrder->fresh(['items.product']);
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
