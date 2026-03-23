<?php

namespace App\Services;

use App\Models\DistOrder;
use App\Models\User;

class CommissionService
{
    /**
     * Build commission payloads from the current user hierarchy.
     * We infer the recipient chain from user ancestry because that is the
     * hierarchy the rebuilt schema models explicitly and consistently.
     */
    public function calculateDispatchCommissionPayloads(DistOrder $order): array
    {
        $order->loadMissing([
            'items.product',
            'user.parent.parent.parent.parent.franchisee',
            'franchisee',
        ]);

        $commissionableAmount = 0.0;
        foreach ($order->items as $item) {
            if ($item->product->is_commissionable) {
                $commissionableAmount += ((float) $item->rate * (float) $item->approved_qty);
            }
        }

        if ($commissionableAmount <= 0) {
            return [];
        }

        $recipientPayloads = [];
        $seenUserIds = [];
        $cursor = $order->user;

        while ($cursor instanceof User) {
            $cursor->loadMissing('parent.franchisee');
            $ancestor = $cursor->parent;

            if (!$ancestor instanceof User) {
                break;
            }

            if (in_array((int) $ancestor->id, $seenUserIds, true)) {
                break;
            }
            $seenUserIds[] = (int) $ancestor->id;

            $configFranchisee = $ancestor->franchisee;
            $commissionPercent = round((float) ($configFranchisee->purchase_commission_percent ?? 0), 2);
            $tdsPercent = round((float) ($configFranchisee->tds_percent ?? 0), 2);

            if ($configFranchisee && $commissionPercent > 0) {
                $grossCommission = round($commissionableAmount * ($commissionPercent / 100), 2);
                $tdsAmount = round($grossCommission * ($tdsPercent / 100), 2);
                $netPayable = round($grossCommission - $tdsAmount, 2);

                $recipientPayloads[] = [
                    'user_id' => $ancestor->id,
                    'dist_order_id' => $order->id,
                    'type' => 'purchase_commission',
                    'cr_dr' => 'Cr',
                    'base_amount' => round($commissionableAmount, 2),
                    'commission_percent' => $commissionPercent,
                    'gross_commission' => $grossCommission,
                    'tds_percent' => $tdsPercent,
                    'tds_amount' => $tdsAmount,
                    'net_payable' => $netPayable,
                    'description' => "Purchase commission accrued on dispatch for order {$order->order_number}",
                    'status' => 'pending',
                ];
            }

            $cursor = $ancestor;
        }

        return $recipientPayloads;
    }
}
