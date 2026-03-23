<?php

namespace App\Services;

use App\Models\DistOrder;
use App\Models\DistOrderItem;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class DistOrderReviewService
{
    private const ORDER_LOCK_TIMEOUT_MINUTES = 10;

    public function __construct(
        private DistOrderWorkflowService $distOrderWorkflowService,
        private CommissionLifecycleService $commissionLifecycleService
    ) {}

    public function approve(DistOrder $distOrder, User $actor, array $itemsPayload): DistOrder
    {
        return DB::transaction(function () use ($distOrder, $actor, $itemsPayload) {
            $lockedOrder = DistOrder::whereKey($distOrder->id)
                ->lockForUpdate()
                ->with(['items.product', 'franchisee'])
                ->firstOrFail();

            if ($lockedOrder->status !== 'pending') {
                throw new DomainException('Only pending orders can be accepted.');
            }

            $this->assertLockOwnership($actor, $lockedOrder);

            [$totalTaxable, $totalGst, $billableLines] = $this->applyApprovedItems($lockedOrder, $itemsPayload);

            if ($billableLines === 0) {
                throw new DomainException('At least one line must have approved or free quantity before accepting the order.');
            }

            $totalAmount = round($totalTaxable + $totalGst, 2);
            $roundOff = round(round($totalAmount) - $totalAmount, 2);

            $this->distOrderWorkflowService->transition(
                $lockedOrder,
                'accepted',
                $actor,
                [
                    'subtotal' => round($totalTaxable, 2),
                    'total_amount' => round($totalAmount + $roundOff, 2),
                    'round_off' => $roundOff,
                    'accepted_by' => $actor->id,
                    'accepted_at' => now(),
                    'rejection_reason' => null,
                    'locked_by' => null,
                    'locked_at' => null,
                ],
                'Order commercially approved by HO. Awaiting warehouse allocation.',
                [
                    'taxable_total' => round($totalTaxable, 2),
                    'gst_total' => round($totalGst, 2),
                    'billable_line_count' => $billableLines,
                    'line_count' => count($itemsPayload),
                ]
            );

            return $lockedOrder->fresh(['items.product', 'franchisee', 'commissions']);
        });
    }

    public function reject(DistOrder $distOrder, User $actor, string $reason): DistOrder
    {
        return DB::transaction(function () use ($distOrder, $actor, $reason) {
            $lockedOrder = DistOrder::whereKey($distOrder->id)
                ->lockForUpdate()
                ->with('commissions')
                ->firstOrFail();

            if (in_array($lockedOrder->status, ['dispatched', 'delivered', 'cancelled'], true)) {
                throw new DomainException('Cannot reject an order in this status.');
            }

            $this->assertLockOwnership($actor, $lockedOrder);

            $this->commissionLifecycleService->reverseForOrder($lockedOrder, $actor, $reason);

            $this->distOrderWorkflowService->transition(
                $lockedOrder,
                'rejected',
                $actor,
                [
                    'rejection_reason' => $reason,
                    'locked_by' => null,
                    'locked_at' => null,
                ],
                'Order rejected by HO review team.',
                [
                    'rejection_reason' => $reason,
                ]
            );

            return $lockedOrder->fresh();
        });
    }

    private function applyApprovedItems(DistOrder $lockedOrder, array $itemsPayload): array
    {
        $totalTaxable = 0.0;
        $totalGst = 0.0;
        $billableLines = 0;
        $seenItemIds = [];

        foreach ($itemsPayload as $itemData) {
            $itemId = (int) $itemData['id'];

            if (in_array($itemId, $seenItemIds, true)) {
                throw new DomainException('Duplicate order item payload detected. Please refresh and retry.');
            }
            $seenItemIds[] = $itemId;

            /** @var DistOrderItem|null $item */
            $item = $lockedOrder->items->firstWhere('id', $itemId);
            if (!$item) {
                throw new DomainException('One or more order items are invalid for this order.');
            }

            $approvedQty = round((float) $itemData['approved_qty'], 2);
            $freeQty = round((float) ($itemData['free_qty'] ?? 0), 2);
            $requiredQty = round($approvedQty + $freeQty, 2);

            if ($approvedQty < 0 || $freeQty < 0) {
                throw new DomainException("Quantity cannot be negative for Product {$item->product->product_name}.");
            }

            if ($requiredQty > 0) {
                $billableLines++;
            }

            $rate = round((float) $itemData['rate'], 2);
            $discountPercent = round((float) ($itemData['discount_percent'] ?? 0), 2);
            $taxableAmount = round(($approvedQty * $rate) * (1 - ($discountPercent / 100)), 2);
            $gstAmount = round($taxableAmount * ((float) $item->gst_percent / 100), 2);
            $lineTotal = round($taxableAmount + $gstAmount, 2);

            $item->update([
                // Batch ownership belongs to the allocation step, not commercial review.
                'batch_no' => null,
                'expiry_date' => null,
                'approved_qty' => $approvedQty,
                'free_qty' => $freeQty,
                'rate' => $rate,
                'discount_percent' => $discountPercent,
                'taxable_amount' => $taxableAmount,
                'gst_amount' => $gstAmount,
                'total_amount' => $lineTotal,
            ]);

            $totalTaxable += $taxableAmount;
            $totalGst += $gstAmount;
        }

        return [
            round($totalTaxable, 2),
            round($totalGst, 2),
            $billableLines,
        ];
    }

    private function assertLockOwnership(User $actor, DistOrder $distOrder): void
    {
        if (!$this->shouldEnforceOrderLock($distOrder)) {
            return;
        }

        $ownerId = (int) ($distOrder->locked_by ?? 0);
        if ($ownerId === 0 || $ownerId === (int) $actor->id || $this->isOrderLockExpired($distOrder->locked_at)) {
            return;
        }

        $lockedByName = User::query()->whereKey($ownerId)->value('name') ?? 'another user';
        throw new DomainException("Order is currently being edited by {$lockedByName}. Please try after lock timeout or force unlock.");
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
}
