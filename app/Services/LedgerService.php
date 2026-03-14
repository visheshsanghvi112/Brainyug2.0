<?php

namespace App\Services;

use App\Models\FinancialLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class LedgerService
{
    /**
     * Record a financial transaction in the universal ledger.
     *
     * RACE CONDITION SAFE: The balance-read is done inside a DB transaction
     * with a SELECT FOR UPDATE lock on the last row. Two concurrent POS bills
     * hitting this simultaneously will serialize, not interleave.
     *
     * Legacy: Had no locking at all — concurrent tbl_balance writes could
     * produce wrong running totals.
     *
     * @param Model $ledgerable The entity owning the ledger (Franchisee / Supplier)
     * @param string $transactionType e.g., 'SALE', 'PAYMENT_RECEIVED', 'PURCHASE'
     * @param float $debit  Money owed / Money going out
     * @param float $credit Money received / Money coming in
     * @param Model|null $reference The source model (SalesInvoice, DistOrder, etc.)
     * @param string|null $paymentMode CASH, BANK, CREDIT, etc.
     * @param string|null $narration Human-readable description
     */
    public function recordEntry(
        Model $ledgerable,
        string $transactionType,
        float $debit = 0,
        float $credit = 0,
        ?Model $reference = null,
        ?string $paymentMode = null,
        ?string $narration = null,
        string|Carbon|null $transactionDate = null
    ): FinancialLedger {
        return DB::transaction(function () use (
            $ledgerable, $transactionType, $debit, $credit,
            $reference, $paymentMode, $narration, $transactionDate
        ) {
            // Lock the last ledger row for this entity before reading its balance.
            // Without this lock, two concurrent writes read the same balance and
            // both add to it, leaving the running total permanently wrong.
            $lastEntry = FinancialLedger::where('ledgerable_type', get_class($ledgerable))
                ->where('ledgerable_id', $ledgerable->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $previousBalance = $lastEntry ? (float) $lastEntry->running_balance : 0.0;

            // Balance = Previous + Credit - Debit
            // Franchisees: Credit = payment received from them, Debit = bill raised
            // Suppliers:   Credit = bill raised by them,        Debit = payment sent
            $newBalance = round($previousBalance + $credit - $debit, 2);

            $ledger = new FinancialLedger([
                'ledgerable_type' => get_class($ledgerable),
                'ledgerable_id'   => $ledgerable->id,
                'transaction_date' => $transactionDate
                    ? Carbon::parse($transactionDate)->toDateString()
                    : now()->toDateString(),
                'transaction_type' => $transactionType,
                'voucher_no'      => 'V-' . strtoupper(substr(uniqid(), -8)),
                'debit'           => $debit,
                'credit'          => $credit,
                'running_balance' => $newBalance,
                'payment_mode'    => $paymentMode,
                'narration'       => $narration,
            ]);

            if ($reference) {
                $ledger->reference()->associate($reference);
            }

            $ledger->save();

            return $ledger;
        });
    }
}

