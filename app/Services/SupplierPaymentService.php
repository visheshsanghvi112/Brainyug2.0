<?php

namespace App\Services;

use App\Models\FinancialLedger;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPaymentAllocation;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SupplierPaymentService
{
    public function __construct(
        private LedgerService $ledgerService
    ) {}

    /**
     * Build invoice-level payable snapshots for a supplier.
     *
     * Persisted payment allocations are respected first. Any older payment ledger
     * entries that predate allocations are then distributed FIFO in-memory so the
     * outstanding picture remains correct for historical data as well.
     */
    public function invoiceSnapshots(Supplier $supplier): Collection
    {
        $invoices = PurchaseInvoice::query()
            ->where('supplier_id', $supplier->id)
            ->approved()
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get([
                'id',
                'supplier_id',
                'invoice_number',
                'supplier_invoice_no',
                'invoice_date',
                'due_days',
                'total_amount',
                'status',
            ]);

        if ($invoices->isEmpty()) {
            return collect();
        }

        $invoiceIds = $invoices->pluck('id');

        $returnsByInvoice = DB::table('purchase_returns')
            ->selectRaw('purchase_invoice_id, COALESCE(SUM(total_amount), 0) as total_amount')
            ->whereIn('purchase_invoice_id', $invoiceIds)
            ->where('status', 'approved')
            ->whereNull('reversed_at')
            ->groupBy('purchase_invoice_id')
            ->pluck('total_amount', 'purchase_invoice_id');

        $allocationRows = SupplierPaymentAllocation::query()
            ->where('supplier_id', $supplier->id)
            ->whereIn('purchase_invoice_id', $invoiceIds)
            ->get(['purchase_invoice_id', 'financial_ledger_id', 'amount']);

        $allocatedByInvoice = $allocationRows
            ->groupBy('purchase_invoice_id')
            ->map(fn (Collection $rows) => round((float) $rows->sum('amount'), 2));

        $allocatedByLedger = $allocationRows
            ->groupBy('financial_ledger_id')
            ->map(fn (Collection $rows) => round((float) $rows->sum('amount'), 2));

        $paymentLedgers = $supplier->financialLedgers()
            ->where('transaction_type', 'PAYMENT_MADE')
            ->whereNull('reversed_at')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get(['id', 'transaction_date', 'debit']);

        $snapshots = $invoices->map(function (PurchaseInvoice $invoice) use ($returnsByInvoice, $allocatedByInvoice) {
            $dueDate = $invoice->invoice_date?->copy()->addDays((int) ($invoice->due_days ?? 0));
            $returnAdjustedAmount = round((float) ($returnsByInvoice[$invoice->id] ?? 0), 2);
            $grossAmount = round((float) $invoice->total_amount, 2);
            $netPayable = round(max(0, $grossAmount - $returnAdjustedAmount), 2);
            $persistedPaidAmount = round((float) ($allocatedByInvoice[$invoice->id] ?? 0), 2);

            return [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'supplier_invoice_no' => $invoice->supplier_invoice_no,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'due_days' => (int) ($invoice->due_days ?? 0),
                'due_date' => $dueDate?->toDateString(),
                'status' => $invoice->status,
                'gross_amount' => $grossAmount,
                'return_adjusted_amount' => $returnAdjustedAmount,
                'net_payable_amount' => $netPayable,
                'persisted_paid_amount' => $persistedPaidAmount,
                'virtual_paid_amount' => 0.0,
                'paid_amount' => $persistedPaidAmount,
                'outstanding_amount' => round(max(0, $netPayable - $persistedPaidAmount), 2),
                'is_overdue' => false,
            ];
        })->values()->all();

        foreach ($paymentLedgers as $paymentLedger) {
            $unallocatedAmount = round(
                max(0, (float) $paymentLedger->debit - (float) ($allocatedByLedger[$paymentLedger->id] ?? 0)),
                2
            );

            if ($unallocatedAmount <= 0) {
                continue;
            }

            foreach ($snapshots as $index => $snapshot) {
                if ($unallocatedAmount <= 0) {
                    break;
                }

                $outstanding = round((float) $snapshot['outstanding_amount'], 2);
                if ($outstanding <= 0) {
                    continue;
                }

                $allocation = round(min($unallocatedAmount, $outstanding), 2);
                $snapshots[$index]['virtual_paid_amount'] = round((float) $snapshot['virtual_paid_amount'] + $allocation, 2);
                $snapshots[$index]['paid_amount'] = round((float) $snapshot['paid_amount'] + $allocation, 2);
                $snapshots[$index]['outstanding_amount'] = round(max(0, $outstanding - $allocation), 2);
                $unallocatedAmount = round(max(0, $unallocatedAmount - $allocation), 2);
            }
        }

        $today = now()->startOfDay();

        return collect($snapshots)
            ->map(function (array $snapshot) use ($today) {
                $dueDate = $snapshot['due_date'] ? Carbon::parse($snapshot['due_date'])->startOfDay() : null;
                $snapshot['is_overdue'] = (float) $snapshot['outstanding_amount'] > 0
                    && $dueDate !== null
                    && $dueDate->lt($today);

                return $snapshot;
            })
            ->sort(function (array $left, array $right) {
                $leftKey = [
                    (float) $left['outstanding_amount'] > 0 ? 0 : 1,
                    $left['due_date'] ?? '9999-12-31',
                    $left['invoice_date'] ?? '9999-12-31',
                    $left['id'],
                ];
                $rightKey = [
                    (float) $right['outstanding_amount'] > 0 ? 0 : 1,
                    $right['due_date'] ?? '9999-12-31',
                    $right['invoice_date'] ?? '9999-12-31',
                    $right['id'],
                ];

                return $leftKey <=> $rightKey;
            })
            ->values();
    }

    public function recordPayment(Supplier $supplier, User $actor, array $validated): FinancialLedger
    {
        return DB::transaction(function () use ($supplier, $actor, $validated) {
            $amount = round((float) $validated['amount'], 2);
            $currentBalance = round((float) ($supplier->financialLedgers()->latest('id')->value('running_balance') ?? 0), 2);

            if ($currentBalance <= 0) {
                throw new DomainException('This supplier does not have any outstanding payable to settle.');
            }

            $snapshots = $this->invoiceSnapshots($supplier)
                ->filter(fn (array $snapshot) => (float) $snapshot['outstanding_amount'] > 0)
                ->values();

            $invoiceOutstanding = round((float) $snapshots->sum('outstanding_amount'), 2);
            $availableToSettle = round(min($currentBalance, $invoiceOutstanding), 2);

            if ($availableToSettle <= 0) {
                throw new DomainException('No approved supplier invoices are currently open for settlement.');
            }

            if ($amount > $availableToSettle) {
                throw new DomainException("Payment cannot exceed open invoice exposure of {$availableToSettle}.");
            }

            $ledger = $this->ledgerService->recordEntry(
                $supplier,
                'PAYMENT_MADE',
                debit: $amount,
                credit: 0,
                reference: null,
                paymentMode: strtolower((string) $validated['payment_mode']),
                narration: $validated['narration'] ?: "Supplier payment recorded for {$supplier->name}",
                transactionDate: $validated['payment_date'],
            );

            $remaining = $amount;
            foreach ($snapshots as $snapshot) {
                if ($remaining <= 0) {
                    break;
                }

                $outstanding = round((float) $snapshot['outstanding_amount'], 2);
                if ($outstanding <= 0) {
                    continue;
                }

                $allocation = round(min($remaining, $outstanding), 2);

                SupplierPaymentAllocation::create([
                    'supplier_id' => $supplier->id,
                    'purchase_invoice_id' => $snapshot['id'],
                    'financial_ledger_id' => $ledger->id,
                    'allocation_date' => Carbon::parse($validated['payment_date'])->toDateString(),
                    'amount' => $allocation,
                ]);

                $remaining = round(max(0, $remaining - $allocation), 2);
            }

            if ($remaining > 0) {
                throw new DomainException('Payment allocation failed to settle the requested amount against open invoices.');
            }

            return $ledger;
        });
    }

    public function reversePayment(Supplier $supplier, FinancialLedger $paymentLedger, User $actor, array $validated): FinancialLedger
    {
        return DB::transaction(function () use ($supplier, $paymentLedger, $actor, $validated) {
            $lockedPayment = FinancialLedger::query()
                ->whereKey($paymentLedger->id)
                ->where('ledgerable_type', Supplier::class)
                ->where('ledgerable_id', $supplier->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->transaction_type !== 'PAYMENT_MADE') {
                throw new DomainException('Only supplier payment entries can be reversed from this desk.');
            }

            if ($lockedPayment->reversed_at) {
                throw new DomainException('This supplier payment has already been reversed.');
            }

            $amount = round((float) $lockedPayment->debit, 2);
            if ($amount <= 0) {
                throw new DomainException('Only debit payment entries can be reversed.');
            }

            $allocations = SupplierPaymentAllocation::query()
                ->where('financial_ledger_id', $lockedPayment->id)
                ->lockForUpdate()
                ->get();

            if ($allocations->isEmpty()) {
                throw new DomainException('Legacy or unallocated supplier payments cannot be auto-reversed yet.');
            }

            $reversalDate = Carbon::parse($validated['reversal_date'])->toDateString();
            $reversalReason = trim((string) ($validated['reason'] ?? ''));
            if ($reversalReason === '') {
                throw new DomainException('Reversal reason is required.');
            }

            $reversalLedger = $this->ledgerService->recordEntry(
                $supplier,
                'PAYMENT_REVERSAL',
                debit: 0,
                credit: $amount,
                reference: null,
                paymentMode: 'adjustment',
                narration: "Reversal of supplier payment {$lockedPayment->voucher_no}: {$reversalReason}",
                transactionDate: $reversalDate,
            );

            $reversalLedger->update([
                'reverses_financial_ledger_id' => $lockedPayment->id,
                'reversal_reason' => $reversalReason,
            ]);

            foreach ($allocations as $allocation) {
                SupplierPaymentAllocation::create([
                    'supplier_id' => $allocation->supplier_id,
                    'purchase_invoice_id' => $allocation->purchase_invoice_id,
                    'financial_ledger_id' => $reversalLedger->id,
                    'allocation_date' => $reversalDate,
                    'amount' => round(-1 * (float) $allocation->amount, 2),
                ]);
            }

            $lockedPayment->update([
                'reversed_by' => $actor->id,
                'reversed_at' => now(),
                'reversal_reason' => $reversalReason,
            ]);

            return $reversalLedger;
        });
    }

    public function reallocatePayment(Supplier $supplier, FinancialLedger $paymentLedger, User $actor, array $validated): void
    {
        DB::transaction(function () use ($supplier, $paymentLedger, $actor, $validated) {
            $lockedPayment = FinancialLedger::query()
                ->whereKey($paymentLedger->id)
                ->where('ledgerable_type', Supplier::class)
                ->where('ledgerable_id', $supplier->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->transaction_type !== 'PAYMENT_MADE') {
                throw new DomainException('Only supplier payment entries can be reallocated from this desk.');
            }

            if ($lockedPayment->reversed_at) {
                throw new DomainException('Reversed supplier payments cannot be reallocated.');
            }

            $paymentAmount = round((float) $lockedPayment->debit, 2);
            if ($paymentAmount <= 0) {
                throw new DomainException('Only debit payment entries can be reallocated.');
            }

            $requestedAllocations = collect($validated['allocations'] ?? [])
                ->map(function (array $row) {
                    return [
                        'purchase_invoice_id' => (int) ($row['purchase_invoice_id'] ?? 0),
                        'amount' => round((float) ($row['amount'] ?? 0), 2),
                    ];
                })
                ->filter(fn (array $row) => $row['purchase_invoice_id'] > 0 && $row['amount'] > 0)
                ->groupBy('purchase_invoice_id')
                ->map(fn (Collection $rows, int|string $invoiceId) => [
                    'purchase_invoice_id' => (int) $invoiceId,
                    'amount' => round((float) $rows->sum('amount'), 2),
                ])
                ->values();

            if ($requestedAllocations->isEmpty()) {
                throw new DomainException('Add at least one invoice allocation before saving the payment correction.');
            }

            $requestedTotal = round((float) $requestedAllocations->sum('amount'), 2);
            if (abs($requestedTotal - $paymentAmount) > 0.009) {
                throw new DomainException("Reallocation total must exactly match the payment amount of {$paymentAmount}.");
            }

            $reallocationReason = trim((string) ($validated['reason'] ?? ''));
            if ($reallocationReason === '') {
                throw new DomainException('Reallocation reason is required.');
            }

            $allocationDate = Carbon::parse($validated['reallocation_date'])->toDateString();

            $currentAllocations = SupplierPaymentAllocation::query()
                ->where('financial_ledger_id', $lockedPayment->id)
                ->lockForUpdate()
                ->get();

            $currentByInvoice = $currentAllocations
                ->groupBy('purchase_invoice_id')
                ->map(fn (Collection $rows) => round((float) $rows->sum('amount'), 2));

            $settlementSnapshots = $this->lockedInvoiceSettlementSnapshots($supplier)
                ->keyBy('id');

            foreach ($requestedAllocations as $allocation) {
                $snapshot = $settlementSnapshots->get($allocation['purchase_invoice_id']);
                if ($snapshot === null) {
                    throw new DomainException('Payments can only be allocated to approved purchase invoices for this supplier.');
                }

                $currentOnInvoice = (float) ($currentByInvoice[$allocation['purchase_invoice_id']] ?? 0);
                $allocatable = round((float) $snapshot['outstanding_amount'] + $currentOnInvoice, 2);

                if ($allocation['amount'] > $allocatable + 0.009) {
                    throw new DomainException("Allocation for invoice {$snapshot['invoice_number']} exceeds its open payable capacity.");
                }
            }

            foreach ($currentByInvoice as $invoiceId => $amount) {
                if ((float) $amount === 0.0) {
                    continue;
                }

                SupplierPaymentAllocation::create([
                    'supplier_id' => $supplier->id,
                    'purchase_invoice_id' => (int) $invoiceId,
                    'financial_ledger_id' => $lockedPayment->id,
                    'allocation_date' => $allocationDate,
                    'amount' => round(-1 * (float) $amount, 2),
                ]);
            }

            foreach ($requestedAllocations as $allocation) {
                SupplierPaymentAllocation::create([
                    'supplier_id' => $supplier->id,
                    'purchase_invoice_id' => $allocation['purchase_invoice_id'],
                    'financial_ledger_id' => $lockedPayment->id,
                    'allocation_date' => $allocationDate,
                    'amount' => $allocation['amount'],
                ]);
            }

            $narrationParts = array_filter([
                trim((string) $lockedPayment->narration),
                "Reallocated on {$allocationDate}: {$reallocationReason}",
            ]);

            $lockedPayment->update([
                'narration' => implode(' | ', $narrationParts),
            ]);
        });
    }

    private function lockedInvoiceSettlementSnapshots(Supplier $supplier): Collection
    {
        $invoices = PurchaseInvoice::query()
            ->where('supplier_id', $supplier->id)
            ->approved()
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get([
                'id',
                'invoice_number',
                'supplier_invoice_no',
                'invoice_date',
                'due_days',
                'total_amount',
                'status',
            ]);

        if ($invoices->isEmpty()) {
            return collect();
        }

        $invoiceIds = $invoices->pluck('id');

        $returnsByInvoice = DB::table('purchase_returns')
            ->selectRaw('purchase_invoice_id, COALESCE(SUM(total_amount), 0) as total_amount')
            ->whereIn('purchase_invoice_id', $invoiceIds)
            ->where('status', 'approved')
            ->whereNull('reversed_at')
            ->groupBy('purchase_invoice_id')
            ->pluck('total_amount', 'purchase_invoice_id');

        $allocatedByInvoice = SupplierPaymentAllocation::query()
            ->where('supplier_id', $supplier->id)
            ->whereIn('purchase_invoice_id', $invoiceIds)
            ->lockForUpdate()
            ->get(['purchase_invoice_id', 'amount'])
            ->groupBy('purchase_invoice_id')
            ->map(fn (Collection $rows) => round((float) $rows->sum('amount'), 2));

        return $invoices->map(function (PurchaseInvoice $invoice) use ($returnsByInvoice, $allocatedByInvoice) {
            $returnAdjustedAmount = round((float) ($returnsByInvoice[$invoice->id] ?? 0), 2);
            $grossAmount = round((float) $invoice->total_amount, 2);
            $netPayable = round(max(0, $grossAmount - $returnAdjustedAmount), 2);
            $paidAmount = round((float) ($allocatedByInvoice[$invoice->id] ?? 0), 2);

            return [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'supplier_invoice_no' => $invoice->supplier_invoice_no,
                'net_payable_amount' => $netPayable,
                'paid_amount' => $paidAmount,
                'outstanding_amount' => round(max(0, $netPayable - $paidAmount), 2),
            ];
        });
    }
}
