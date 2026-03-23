<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\DistOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CommissionLifecycleService
{
    public function __construct(
        private CommissionService $commissionService,
        private LedgerService $ledgerService
    ) {}

    public function generateForDispatch(DistOrder $order): int
    {
        return DB::transaction(function () use ($order) {
            $order->loadMissing(['franchisee', 'user', 'items.product']);

            $existingActiveCredits = Commission::query()
                ->where('dist_order_id', $order->id)
                ->where('cr_dr', 'Cr')
                ->whereNotIn('status', ['reversed', 'cancelled'])
                ->count();

            if ($existingActiveCredits > 0) {
                $this->syncOrderTotal($order);

                return $existingActiveCredits;
            }

            $payloads = $this->commissionService->calculateDispatchCommissionPayloads($order);
            $created = 0;

            foreach ($payloads as $payload) {
                $commission = Commission::create(array_merge($payload, [
                    'trigger_event' => 'dispatch',
                ]));

                $recipient = User::query()->find($commission->user_id);
                if ($recipient) {
                    $this->ledgerService->recordEntry(
                        ledgerable: $recipient,
                        transactionType: 'COMMISSION',
                        debit: 0,
                        credit: (float) $commission->net_payable,
                        reference: $commission,
                        paymentMode: 'Adjustment',
                        narration: "Commission earned on dispatch for order {$order->order_number}",
                        transactionDate: $order->dispatch_date ?? null
                    );
                }

                $created++;
            }

            $this->syncOrderTotal($order);

            return $created;
        });
    }

    public function reverseForOrder(DistOrder $order, User $actor, string $reason): int
    {
        return DB::transaction(function () use ($order, $actor, $reason) {
            $activeCredits = Commission::query()
                ->where('dist_order_id', $order->id)
                ->where('cr_dr', 'Cr')
                ->whereNotIn('status', ['reversed', 'cancelled'])
                ->lockForUpdate()
                ->get();

            $reversed = 0;

            foreach ($activeCredits as $commission) {
                $reversal = Commission::create([
                    'user_id' => $commission->user_id,
                    'dist_order_id' => $commission->dist_order_id,
                    'type' => $commission->type,
                    'cr_dr' => 'Dr',
                    'base_amount' => $commission->base_amount,
                    'commission_percent' => $commission->commission_percent,
                    'gross_commission' => $commission->gross_commission,
                    'tds_percent' => $commission->tds_percent,
                    'tds_amount' => $commission->tds_amount,
                    'net_payable' => $commission->net_payable,
                    'description' => "Reversal of commission for order {$order->order_number}: {$reason}",
                    'status' => 'reversed',
                    'trigger_event' => 'reversal',
                    'reverses_commission_id' => $commission->id,
                ]);

                $recipient = User::query()->find($commission->user_id);
                if ($recipient) {
                    $this->ledgerService->recordEntry(
                        ledgerable: $recipient,
                        transactionType: 'COMMISSION_REVERSAL',
                        debit: (float) $commission->net_payable,
                        credit: 0,
                        reference: $reversal,
                        paymentMode: 'Adjustment',
                        narration: "Commission reversed for order {$order->order_number}: {$reason}",
                        transactionDate: now()
                    );
                }

                $commission->update([
                    'status' => 'reversed',
                    'reversed_by' => $actor->id,
                    'reversed_at' => now(),
                    'reversal_reason' => $reason,
                ]);

                $reversed++;
            }

            $this->syncOrderTotal($order);

            return $reversed;
        });
    }

    public function syncOrderTotal(DistOrder $order): void
    {
        $activeGross = Commission::query()
            ->where('dist_order_id', $order->id)
            ->where('cr_dr', 'Cr')
            ->whereNotIn('status', ['reversed', 'cancelled'])
            ->sum('gross_commission');

        $order->update([
            'total_commission' => round((float) $activeGross, 2),
        ]);
    }
}
