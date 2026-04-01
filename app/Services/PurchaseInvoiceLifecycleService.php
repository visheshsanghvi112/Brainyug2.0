<?php

namespace App\Services;

use App\Models\PurchaseInvoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseInvoiceLifecycleService
{
    public function __construct(
        private InventoryService $inventoryService,
        private LedgerService $ledgerService
    ) {}

    public function approve(PurchaseInvoice $purchaseInvoice, User $actor): int
    {
        return DB::transaction(function () use ($purchaseInvoice, $actor) {
            $lockedInvoice = PurchaseInvoice::whereKey($purchaseInvoice->id)
                ->lockForUpdate()
                ->with(['items', 'supplier'])
                ->firstOrFail();

            if (!$lockedInvoice->canApprove()) {
                throw ValidationException::withMessages([
                    'status' => 'Only draft invoices can be approved.',
                ]);
            }

            if ((int) $lockedInvoice->created_by === (int) $actor->id && !$actor->isSuperAdmin()) {
                throw ValidationException::withMessages([
                    'approval' => 'Maker-checker rule: the creator cannot approve this invoice. Ask another authorized user to approve.',
                ]);
            }

            $expiredItems = $lockedInvoice->items->filter(function ($item) {
                return $item->expiry_date && Carbon::parse($item->expiry_date)->isPast();
            });

            if ($expiredItems->isNotEmpty()) {
                $list = $expiredItems->map(fn ($item) => "{$item->batch_no} (exp: {$item->expiry_date})")->join(', ');
                throw ValidationException::withMessages([
                    'items' => "Cannot approve: the following batches are already expired - {$list}. Remove or correct them first.",
                ]);
            }

            $nearExpiryCount = $lockedInvoice->items->filter(function ($item) {
                return $item->expiry_date
                    && !Carbon::parse($item->expiry_date)->isPast()
                    && Carbon::parse($item->expiry_date)->diffInDays(now()) <= 90;
            })->count();

            $lockedInvoice->update([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            foreach ($lockedInvoice->items as $item) {
                $this->inventoryService->recordPurchase([
                    'product_id' => $item->product_id,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                    'mfg_date' => $item->mfg_date,
                    'mrp' => $item->mrp,
                    'qty' => $item->qty,
                    'free_qty' => $item->free_qty,
                    'rate' => $item->rate,
                    'reference_id' => $lockedInvoice->id,
                    'created_by' => $actor->id,
                ]);
            }

            $this->ledgerService->recordEntry(
                $lockedInvoice->supplier,
                'PURCHASE',
                debit: 0,
                credit: (float) $lockedInvoice->total_amount,
                reference: $lockedInvoice,
                paymentMode: 'credit',
                narration: "Purchase invoice {$lockedInvoice->invoice_number} approved for {$lockedInvoice->supplier->name}",
                transactionDate: $lockedInvoice->invoice_date,
            );

            return $nearExpiryCount;
        });
    }

    public function cancel(PurchaseInvoice $purchaseInvoice, User $actor): void
    {
        DB::transaction(function () use ($purchaseInvoice, $actor) {
            $lockedInvoice = PurchaseInvoice::whereKey($purchaseInvoice->id)
                ->lockForUpdate()
                ->with(['items', 'supplier', 'purchaseReturns'])
                ->firstOrFail();

            if ($lockedInvoice->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'status' => 'Invoice is already cancelled.',
                ]);
            }

            if ($lockedInvoice->isLegacy()) {
                throw ValidationException::withMessages([
                    'status' => 'Legacy historical invoices are read-only archives and cannot be cancelled. If this stock no longer exists, create a manual stock adjustment instead.',
                ]);
            }

            if ($lockedInvoice->status === 'approved') {
                $activeReturns = $lockedInvoice->purchaseReturns
                    ->filter(fn ($purchaseReturn) => $purchaseReturn->status === 'draft' || $purchaseReturn->isApprovedActive())
                    ->values();

                if ($activeReturns->isNotEmpty()) {
                    $returnRefs = $activeReturns
                        ->map(fn ($purchaseReturn) => $purchaseReturn->return_number)
                        ->implode(', ');

                    throw ValidationException::withMessages([
                        'status' => "Cannot cancel approved invoice {$lockedInvoice->invoice_number}: linked purchase return(s) {$returnRefs} already exist. Cancel draft returns or reverse approved returns first.",
                    ]);
                }

                foreach ($lockedInvoice->items as $item) {
                    $requiredQty = (float) $item->qty + (float) $item->free_qty;

                    if (!$this->inventoryService->hasSufficientStock(
                        (int) $item->product_id,
                        (string) $item->batch_no,
                        'warehouse',
                        0,
                        $requiredQty
                    )) {
                        throw ValidationException::withMessages([
                            'status' => "Cannot cancel approved invoice {$lockedInvoice->invoice_number}: stock for product {$item->product_id} batch {$item->batch_no} has already been consumed. Use purchase return or stock adjustment workflow instead.",
                        ]);
                    }
                }

                foreach ($lockedInvoice->items as $item) {
                    $this->inventoryService->recordAdjustment([
                        'product_id' => $item->product_id,
                        'batch_no' => $item->batch_no,
                        'expiry_date' => $item->expiry_date,
                        'mrp' => $item->mrp,
                        'location_type' => 'warehouse',
                        'location_id' => 0,
                        'qty' => -((float) $item->qty + (float) $item->free_qty),
                        'rate' => $item->rate,
                        'created_by' => $actor->id,
                        'remarks' => "Reversal: Purchase Invoice {$lockedInvoice->invoice_number} cancelled",
                    ]);
                }

                $this->ledgerService->recordEntry(
                    $lockedInvoice->supplier,
                    'PURCHASE_CANCELLED',
                    debit: (float) $lockedInvoice->total_amount,
                    credit: 0,
                    reference: $lockedInvoice,
                    paymentMode: 'adjustment',
                    narration: "Purchase invoice {$lockedInvoice->invoice_number} cancelled and payable reversed",
                    transactionDate: now(),
                );
            }

            $lockedInvoice->update(['status' => 'cancelled']);
        });
    }
}
