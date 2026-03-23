<?php

namespace App\Services;

use App\Models\DistOrder;
use App\Models\DistOrderPayment;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class DistOrderPaymentService
{
    public function __construct(
        private LedgerService $ledgerService
    ) {}

    public function submit(DistOrder $distOrder, User $actor, array $payload): DistOrderPayment
    {
        return DB::transaction(function () use ($distOrder, $actor, $payload) {
            $lockedOrder = DistOrder::whereKey($distOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!in_array($lockedOrder->status, ['dispatched', 'delivered'], true)) {
                throw new DomainException('Payments can only be submitted after the order is dispatched.');
            }

            $reservedAmount = (float) DistOrderPayment::query()
                ->where('dist_order_id', $lockedOrder->id)
                ->whereIn('status', ['pending', 'confirmed'])
                ->lockForUpdate()
                ->sum('amount');

            $availableToSubmit = round(max(0, (float) $lockedOrder->total_amount - $reservedAmount), 2);
            $requestedAmount = round((float) $payload['amount'], 2);

            if ($requestedAmount > $availableToSubmit) {
                throw new DomainException("Payment exceeds outstanding amount available for submission. Available amount is {$availableToSubmit}.");
            }

            return DistOrderPayment::create([
                'dist_order_id' => $lockedOrder->id,
                'franchisee_id' => $lockedOrder->franchisee_id,
                'created_by' => $actor->id,
                'amount' => $requestedAmount,
                'payment_mode' => $payload['payment_mode'],
                'reference_no' => $payload['reference_no'] ?? null,
                'payment_date' => $payload['payment_date'],
                'narration' => $payload['narration'] ?? null,
            ]);
        });
    }

    public function confirm(DistOrder $distOrder, DistOrderPayment $distOrderPayment, User $actor): DistOrderPayment
    {
        return DB::transaction(function () use ($distOrder, $distOrderPayment, $actor) {
            $lockedOrder = DistOrder::whereKey($distOrder->id)
                ->lockForUpdate()
                ->with('franchisee')
                ->firstOrFail();

            $payment = DistOrderPayment::whereKey($distOrderPayment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->dist_order_id !== $lockedOrder->id) {
                throw new DomainException('Payment does not belong to this order.');
            }

            if ($payment->status !== 'pending') {
                throw new DomainException('Only pending payments can be confirmed.');
            }

            $ledger = $this->ledgerService->recordEntry(
                ledgerable: $lockedOrder->franchisee,
                transactionType: 'PAYMENT_RECEIVED',
                debit: 0,
                credit: (float) $payment->amount,
                reference: $payment,
                paymentMode: strtolower((string) $payment->payment_mode),
                narration: $payment->narration
                    ? "B2B payment for Order {$lockedOrder->order_number}: {$payment->narration}"
                    : "B2B payment for Order {$lockedOrder->order_number}",
                transactionDate: $payment->payment_date,
            );

            $payment->update([
                'status' => 'confirmed',
                'financial_ledger_id' => $ledger->id,
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);

            return $payment->fresh();
        });
    }

    public function reject(DistOrder $distOrder, DistOrderPayment $distOrderPayment, User $actor, string $reason): DistOrderPayment
    {
        return DB::transaction(function () use ($distOrder, $distOrderPayment, $actor, $reason) {
            $lockedOrder = DistOrder::whereKey($distOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            $payment = DistOrderPayment::whereKey($distOrderPayment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->dist_order_id !== $lockedOrder->id) {
                throw new DomainException('Payment does not belong to this order.');
            }

            if ($payment->status !== 'pending') {
                throw new DomainException('Only pending payments can be rejected.');
            }

            $payment->update([
                'status' => 'rejected',
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            return $payment->fresh();
        });
    }
}
