<?php

namespace App\Services;

use App\Models\DistOrder;
use App\Models\Commission;
use App\Models\Franchisee;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;

class CommissionService
{
    public function __construct(private LedgerService $ledgerService) {}

    /**
     * Exact replica of the recursive legacy commission logic from Dist_order->accept_order().
     * This traverses up the Franchisee hierarchy (Franchisee -> DH -> ZH -> SH)
     * checking real database percentages and applying TDS dynamically.
     */
    public function generateCommissionsForOrder(DistOrder $order)
    {
        // In legacy: Add up rate_a * qty only where product category='COM'
        $commissionableAmount = 0;
        foreach ($order->items as $item) {
            if ($item->product->is_commissionable) {
                // Rate_a * approved_qty (ignoring free qty usually for base)
                $commissionableAmount += ($item->rate * $item->approved_qty);
            }
        }

        if ($commissionableAmount <= 0) {
            return;
        }

        DB::transaction(function () use ($order, $commissionableAmount) {
            // Find the active franchisee for this order
            $currentFranchisee = $order->franchisee;

            // Traverse up the chain using direct parent logic from Franchisee table
            $parent = $currentFranchisee->parent;

            while ($parent) {
                // Fetch hard-coded database percents mimicking legacy getPurchaseComission()
                $commissionPercent = $parent->purchase_commission_percent;
                $tdsPercent = $parent->tds_percent;

                if ($commissionPercent > 0) {
                    $grossCommission = $commissionableAmount * ($commissionPercent / 100);
                    $tdsAmount = $grossCommission * ($tdsPercent / 100);
                    $netPayable = $grossCommission - $tdsAmount;

                    $commission = Commission::create([
                        // The User who owns this parent franchisee gets the money
                        'user_id' => $parent->owner_id, 
                        'dist_order_id' => $order->id,
                        'type' => 'purchase_commission',
                        'cr_dr' => 'Cr',
                        'base_amount' => $commissionableAmount,
                        'commission_percent' => $commissionPercent,
                        'gross_commission' => $grossCommission,
                        'tds_percent' => $tdsPercent,
                        'tds_amount' => $tdsAmount,
                        'net_payable' => $netPayable,
                        'description' => "Purchase Commission Credited for franchisee order {$order->order_number}",
                        'status' => 'pending'
                    ]);

                    // Record in User's personal ledger
                    $this->ledgerService->recordEntry(
                        ledgerable: \App\Models\User::find($parent->owner_id),
                        transactionType: 'COMMISSION',
                        debit: 0,
                        credit: $netPayable,
                        reference: $commission,
                        paymentMode: 'Adjustment',
                        narration: "Commission Earned (Net of TDS) for order {$order->order_number} by child franchisee {$currentFranchisee->shop_name}"
                    );
                }

                // Move up the recursive chain
                $parent = $parent->parent;
            }
        });
    }
}
