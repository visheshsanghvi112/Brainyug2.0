<?php

namespace App\Services;

use App\Events\FranchiseePurchaseApproved;
use App\Events\FranchiseePurchaseRejected;
use App\Models\Franchisee;
use App\Models\FranchiseePurchase;
use App\Models\FranchiseePurchaseItem;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FranchiseePurchaseService
 * 
 * Manages the complete lifecycle of purchases made by franchisees from external vendors.
 * Handles approval workflows, inventory ledger entries, and stock alert triggers.
 */
class FranchiseePurchaseService
{
    public function __construct(
        private StockMonitoringService $stockMonitoringService,
        private LedgerService $ledgerService,
        private InventoryService $inventoryService
    ) {}

    /**
     * Create a new outside purchase draft (entry point for franchisee or admin).
     */
    public function createDraft(array $data, User $creator): FranchiseePurchase
    {
        return DB::transaction(function () use ($data, $creator) {
            $franchisee = Franchisee::findOrFail($data['franchisee_id']);

            // Generate transaction number
            $lastNumber = FranchiseePurchase::where('financial_year', $data['financial_year'] ?? FranchiseePurchase::currentFinancialYear())
                ->latest('id')
                ->first();
            $nextNum = $lastNumber ? ((int) substr($lastNumber->transaction_number, -4)) + 1 : 1;

            $purchase = FranchiseePurchase::create([
                'transaction_number' => 'FP-' . ($data['financial_year'] ?? FranchiseePurchase::currentFinancialYear()) . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT),
                'franchisee_id' => $franchisee->id,
                'supplier_id' => $data['supplier_id'],
                'created_by' => $creator->id,
                'purchase_date' => $data['purchase_date'],
                'reason_code' => $data['reason_code'] ?? 'normal',
                'financial_year' => $data['financial_year'] ?? FranchiseePurchase::currentFinancialYear(),
                'notes' => $data['notes'] ?? null,
                'approval_status' => 'pending',
                'status' => 'draft',
            ]);

            // Create line items
            foreach ($data['items'] ?? [] as $itemData) {
                $this->createItem($purchase, $itemData);
            }

            // Calculate totals
            $this->recalculateTotals($purchase);

            return $purchase;
        });
    }

    /**
     * Approve a pending purchase (HO/Warehouse).
     * On approval:
     *  1. Creates InventoryLedger entries (UPDATE type)
     *  2. Records ledger entry for franchisee account
     *  3. Triggers stock threshold check
     *  4. Dispatches event for notifications
     */
    public function approvePurchase(FranchiseePurchase $purchase, User $approver): void
    {
        if (!$purchase->canApprove()) {
            throw new DomainException(
                "Purchase {$purchase->transaction_number} cannot be approved. Status: {$purchase->approval_status}"
            );
        }

        DB::transaction(function () use ($purchase, $approver) {
            // Mark as approved
            $purchase->update([
                'approval_status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'status' => 'completed',
            ]);

            // For each item, create inventory ledger entry at franchisee location
            foreach ($purchase->items as $item) {
                $this->inventoryService->recordInventoryUpdate([
                    'product_id' => $item->product_id,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                    'mfg_date' => $item->mfg_date,
                    'mrp' => $item->mrp,
                    'rate' => $item->rate,
                    'franchisee_id' => $purchase->franchisee_id,
                    'qty' => $item->qty,
                    'free_qty' => $item->free_qty,
                    'reason' => 'Outside Purchase Approved',
                    'source_document' => $purchase->transaction_number,
                    'created_by' => $approver->id,
                ]);
            }

            // Record financial ledger entry (franchisee owes HO for goods)
            // In legacy, this would be "purchase from vendor" 
            // Here we record it as HO giving credit to franchisee
            $this->ledgerService->recordEntry(
                ledgerable: $purchase->franchisee,
                transactionType: 'ASSET_TRANSFER',
                debit: (float) $purchase->total_amount,
                credit: 0,
                reference: $purchase,
                paymentMode: 'CREDIT',
                narration: "Outside Purchase Approved - {$purchase->supplier->name} / {$purchase->transaction_number}",
                transactionDate: $purchase->purchase_date
            );

            // Check stock thresholds for each product
            foreach ($purchase->items as $item) {
                $product = $item->product;
                $alert = $this->stockMonitoringService->checkThreshold(
                    product: $product,
                    franchisee: $purchase->franchisee,
                    triggerSource: 'franchisee_purchase_approved',
                    referenceId: $purchase->id,
                    referenceType: 'franchisee_purchase'
                );

                // Log if alert was created
                if ($alert) {
                    // Alert will be picked up by dedicated event listener for notifications
                }
            }

            // Dispatch event for listeners (notifications, audits, etc)
            FranchiseePurchaseApproved::dispatch($purchase, $approver);
        });
    }

    /**
     * Reject a pending purchase.
     */
    public function rejectPurchase(FranchiseePurchase $purchase, string $reason, User $rejector): void
    {
        if (!$purchase->canReject()) {
            throw new DomainException(
                "Purchase {$purchase->transaction_number} cannot be rejected. Status: {$purchase->approval_status}"
            );
        }

        DB::transaction(function () use ($purchase, $reason, $rejector) {
            $purchase->update([
                'approval_status' => 'rejected',
                'rejection_reason' => $reason,
                'approved_by' => $rejector->id,
                'approved_at' => now(),
                'status' => 'cancelled',
            ]);

            FranchiseePurchaseRejected::dispatch($purchase, $reason);
        });
    }

    /**
     * Cancel a completed/approved purchase (revert inventory).
     */
    public function cancelPurchase(FranchiseePurchase $purchase, string $reason, User $canceller): void
    {
        if ($purchase->status !== 'completed') {
            throw new DomainException(
                "Only completed purchases can be cancelled. Current status: {$purchase->status}"
            );
        }

        DB::transaction(function () use ($purchase, $reason, $canceller) {
            // Create reversal inventory entries
            foreach ($purchase->items as $item) {
                $this->inventoryService->recordInventoryUpdate([
                    'product_id' => $item->product_id,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                    'mrp' => $item->mrp,
                    'rate' => $item->rate,
                    'franchisee_id' => $purchase->franchisee_id,
                    'qty' => -$item->qty, // Negative to reverse
                    'free_qty' => -$item->free_qty,
                    'reason' => "Cancellation: {$reason}",
                    'source_document' => "{$purchase->transaction_number}-CANCEL",
                    'created_by' => $canceller->id,
                ]);
            }

            // Reverse ledger entry
            $this->ledgerService->recordEntry(
                ledgerable: $purchase->franchisee,
                transactionType: 'ASSET_TRANSFER_REVERSAL',
                debit: 0,
                credit: (float) $purchase->total_amount,
                reference: $purchase,
                paymentMode: 'CREDIT',
                narration: "Outside Purchase Cancelled - {$reason} / {$purchase->transaction_number}",
                transactionDate: now()->toDateString()
            );

            $purchase->update([
                'status' => 'cancelled',
            ]);
        });
    }

    // ──── Helpers ────

    private function createItem(FranchiseePurchase $purchase, array $itemData): void
    {
        $product = Product::findOrFail($itemData['product_id']);
        $hsn = $product->hsn;

        // Calculate gst based on HSN
        $gstPercent = $itemData['gst_percent'] ?? 0;
        if (!$gstPercent && $hsn) {
            $sameState = isset($hsn->state_id)
                && $purchase->franchisee->state_id
                && (int) $purchase->franchisee->state_id === (int) $hsn->state_id;

            $gstPercent = $sameState
                ? (float) (($hsn->sgst_percent ?? 0) + ($hsn->cgst_percent ?? 0))
                : (float) ($hsn->igst_percent ?? 0);
        }

        $rate = (float) ($itemData['rate'] ?? $product->ptr ?? 0);
        $qty = (float) ($itemData['qty'] ?? 0);
        $discount = (float) ($itemData['discount_amount'] ?? 0);
        $taxableAmount = ($rate - $discount) * $qty;
        $gstAmount = ($taxableAmount * $gstPercent) / 100;
        $totalAmount = $taxableAmount + $gstAmount;

        FranchiseePurchaseItem::create([
            'franchisee_purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'hsn_id' => $hsn?->id,
            'batch_no' => $itemData['batch_no'] ?? '',
            'mfg_date' => $itemData['mfg_date'] ?? null,
            'expiry_date' => $itemData['expiry_date'],
            'qty' => $qty,
            'free_qty' => (float) ($itemData['free_qty'] ?? 0),
            'unit' => $itemData['unit'] ?? 'pcs',
            'mrp' => (float) ($itemData['mrp'] ?? $product->mrp ?? 0),
            'rate' => $rate,
            'discount_percent' => (float) ($itemData['discount_percent'] ?? 0),
            'discount_amount' => $discount,
            'gst_percent' => $gstPercent,
            'gst_amount' => $gstAmount,
            'taxable_amount' => $taxableAmount,
            'total_amount' => $totalAmount,
        ]);
    }

    private function recalculateTotals(FranchiseePurchase $purchase): void
    {
        $items = $purchase->items;

        $subtotal = $items->sum('taxable_amount');
        $totalGst = $items->sum('gst_amount');
        
        // Simplified: assume all items have same tax type
        // In reality, need to split by SGST+CGST vs IGST
        $sgstAmount = $totalGst / 2;
        $cgstAmount = $totalGst / 2;
        $igstAmount = 0;

        $totalAmount = $subtotal + $totalGst;

        $purchase->update([
            'subtotal' => $subtotal,
            'sgst_amount' => $sgstAmount,
            'cgst_amount' => $cgstAmount,
            'igst_amount' => $igstAmount,
            'total_amount' => $totalAmount,
        ]);
    }
}
