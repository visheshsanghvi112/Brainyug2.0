<?php

namespace App\Services;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseReturnLifecycleService
{
    public function __construct(
        private InventoryService $inventoryService,
        private LedgerService $ledgerService
    ) {}

    public function approve(PurchaseReturn $purchaseReturn, User $actor): void
    {
        DB::transaction(function () use ($purchaseReturn, $actor) {
            $lockedReturn = PurchaseReturn::whereKey($purchaseReturn->id)
                ->lockForUpdate()
                ->with(['items', 'supplier', 'purchaseInvoice.items', 'sourceInvoices.items'])
                ->firstOrFail();

            if ($lockedReturn->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Only draft returns can be approved.',
                ]);
            }

            if ((int) $lockedReturn->created_by === (int) $actor->id && !$actor->isSuperAdmin()) {
                throw ValidationException::withMessages([
                    'approval' => 'Maker-checker rule: the creator cannot approve this return. Ask another authorized user to approve.',
                ]);
            }

            $sourceInvoices = $lockedReturn->sourceInvoices;
            if ($sourceInvoices->isEmpty() && $lockedReturn->purchaseInvoice) {
                $sourceInvoices = collect([$lockedReturn->purchaseInvoice]);
            }

            if ($sourceInvoices->isNotEmpty()) {
                if ($sourceInvoices->contains(fn ($invoice) => $invoice->status !== 'approved')) {
                    throw ValidationException::withMessages([
                        'purchase_invoice_id' => 'One or more linked source invoices are no longer approved. Reverse or restore the source invoices before approving this return.',
                    ]);
                }

                $returnItemsPayload = $lockedReturn->items->map(function ($item) {
                    return [
                        'product_id' => $item->product_id,
                        'batch_no' => $item->batch_no,
                        'qty' => (float) $item->qty,
                    ];
                })->all();

                $this->validateInvoicesLinkedReturnItems(
                    $sourceInvoices,
                    $returnItemsPayload,
                    $lockedReturn->id
                );
            }

            $inventoryFailures = [];

            foreach ($lockedReturn->items as $item) {
                if (!$this->inventoryService->hasSufficientStock(
                    (int) $item->product_id,
                    (string) $item->batch_no,
                    'warehouse',
                    0,
                    (float) $item->qty
                )) {
                    $inventoryFailures[] = "Insufficient stock at approval for product ID {$item->product_id}, batch {$item->batch_no}.";
                }
            }

            if (!empty($inventoryFailures)) {
                throw ValidationException::withMessages([
                    'items' => implode(' ', $inventoryFailures),
                ]);
            }

            $lockedReturn->update([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'reversed_by' => null,
                'reversed_at' => null,
                'reversal_reason' => null,
            ]);

            foreach ($lockedReturn->items as $item) {
                $this->inventoryService->recordPurchaseReturn([
                    'product_id' => $item->product_id,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                    'qty' => $item->qty,
                    'rate' => $item->rate,
                    'reference_id' => $lockedReturn->id,
                    'created_by' => $actor->id,
                ]);
            }

            $this->ledgerService->recordEntry(
                $lockedReturn->supplier,
                'PURCHASE_RETURN',
                debit: (float) $lockedReturn->total_amount,
                credit: 0,
                reference: $lockedReturn,
                paymentMode: 'adjustment',
                narration: "Purchase return {$lockedReturn->return_number} approved against supplier {$lockedReturn->supplier->name}",
                transactionDate: $lockedReturn->return_date,
            );
        });
    }

    public function cancel(PurchaseReturn $purchaseReturn): void
    {
        DB::transaction(function () use ($purchaseReturn) {
            $lockedReturn = PurchaseReturn::whereKey($purchaseReturn->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReturn->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'status' => 'Return is already cancelled.',
                ]);
            }

            if ($lockedReturn->isApprovedActive()) {
                throw ValidationException::withMessages([
                    'status' => 'Cannot cancel an approved return directly. Reverse it instead so stock and supplier payable are restored cleanly.',
                ]);
            }

            if ($lockedReturn->isReversed()) {
                throw ValidationException::withMessages([
                    'status' => 'This return has already been reversed and is now read-only for audit purposes.',
                ]);
            }

            $lockedReturn->update(['status' => 'cancelled']);
        });
    }

    public function reverse(PurchaseReturn $purchaseReturn, User $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($purchaseReturn, $actor, $reason) {
            $lockedReturn = PurchaseReturn::whereKey($purchaseReturn->id)
                ->lockForUpdate()
                ->with(['items', 'supplier'])
                ->firstOrFail();

            if (!$lockedReturn->canReverse()) {
                $message = $lockedReturn->isReversed()
                    ? 'This purchase return has already been reversed.'
                    : 'Only approved purchase returns can be reversed.';

                throw ValidationException::withMessages([
                    'status' => $message,
                ]);
            }

            $reversalReason = trim((string) $reason);
            if ($reversalReason === '') {
                $reversalReason = 'Operational correction';
            }

            foreach ($lockedReturn->items as $item) {
                $this->inventoryService->recordPurchaseReturnReversal([
                    'product_id' => $item->product_id,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                    'qty' => $item->qty,
                    'rate' => $item->rate,
                    'reference_id' => $lockedReturn->id,
                    'created_by' => $actor->id,
                    'remarks' => "Reversal of purchase return {$lockedReturn->return_number}: {$reversalReason}",
                ]);
            }

            $this->ledgerService->recordEntry(
                $lockedReturn->supplier,
                'PURCHASE_RETURN_REVERSAL',
                debit: 0,
                credit: (float) $lockedReturn->total_amount,
                reference: $lockedReturn,
                paymentMode: 'adjustment',
                narration: "Purchase return {$lockedReturn->return_number} reversed: {$reversalReason}",
                transactionDate: now(),
            );

            $lockedReturn->update([
                'reversed_by' => $actor->id,
                'reversed_at' => now(),
                'reversal_reason' => $reversalReason,
            ]);
        });
    }

    /**
     * Validate invoice-linked return lines do not exceed purchased quantities
     * for each product+batch, ignoring returns already reversed.
     */
    public function validateInvoiceLinkedReturnItems(PurchaseInvoice $invoice, array $items, ?int $excludingReturnId = null): void
    {
        $this->validateInvoicesLinkedReturnItems(collect([$invoice]), $items, $excludingReturnId);
    }

    public function validateInvoicesLinkedReturnItems(Collection $invoices, array $items, ?int $excludingReturnId = null): void
    {
        if ($invoices->isEmpty()) {
            return;
        }

        $invoiceIds = $invoices->pluck('id')->map(fn ($id) => (int) $id)->values();

        $purchasedByKey = $invoices
            ->flatMap(fn ($invoice) => $invoice->items)
            ->groupBy(fn ($item) => $item->product_id . '|' . $item->batch_no)
            ->map(fn ($group) => (float) $group->sum(fn ($item) => (float) $item->qty + (float) $item->free_qty));

        $approvedReturnIds = PurchaseReturn::query()
            ->where('status', 'approved')
            ->whereNull('reversed_at')
            ->where(function ($query) use ($invoiceIds) {
                $query->whereIn('purchase_invoice_id', $invoiceIds)
                    ->orWhereExists(function ($exists) use ($invoiceIds) {
                        $exists->selectRaw('1')
                            ->from('purchase_return_source_invoices as prsi')
                            ->whereColumn('prsi.purchase_return_id', 'purchase_returns.id')
                            ->whereIn('prsi.purchase_invoice_id', $invoiceIds);
                    });
            })
            ->when($excludingReturnId, fn ($query) => $query->where('id', '!=', $excludingReturnId))
            ->pluck('id');

        $alreadyReturnedByKey = PurchaseReturnItem::query()
            ->selectRaw('purchase_return_items.product_id, purchase_return_items.batch_no, COALESCE(SUM(purchase_return_items.qty), 0) as returned_qty')
            ->whereIn('purchase_return_items.purchase_return_id', $approvedReturnIds)
            ->groupBy('purchase_return_items.product_id', 'purchase_return_items.batch_no')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->product_id . '|' . $row->batch_no => (float) $row->returned_qty,
            ]);

        $requestedByKey = collect($items)
            ->groupBy(fn ($item) => $item['product_id'] . '|' . $item['batch_no'])
            ->map(fn ($group) => (float) $group->sum(fn ($item) => (float) $item['qty']));

        $errors = [];

        foreach ($requestedByKey as $key => $requestedQty) {
            $purchasedQty = (float) ($purchasedByKey[$key] ?? 0);
            $returnedQty = (float) ($alreadyReturnedByKey[$key] ?? 0);

            if ($purchasedQty <= 0) {
                [$productId, $batchNo] = explode('|', $key);
                $errors[] = "Item product {$productId}, batch {$batchNo} does not exist on selected source invoices.";
                continue;
            }

            if (($requestedQty + $returnedQty) > $purchasedQty + 0.0001) {
                [$productId, $batchNo] = explode('|', $key);
                $errors[] = "Return qty exceeds purchased qty for product {$productId}, batch {$batchNo}. Purchased: {$purchasedQty}, already returned: {$returnedQty}, requested: {$requestedQty}.";
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages([
                'items' => implode(' ', $errors),
            ]);
        }
    }
}
