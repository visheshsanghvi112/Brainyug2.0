<?php

namespace App\Services;

use App\Models\InventoryLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * InventoryService — THE source of truth for all stock operations.
 *
 * Replaces legacy:
 *   - Direct tbl_stock UPDATE (no audit trail)
 *   - get_stock() / update_fms_stock() / get_pharmastock()
 *   - Dual-database stock deduction on order acceptance
 *
 * RULES:
 *   1. Never update stock directly — always create ledger entries
 *   2. Current stock = SUM(qty_in) - SUM(qty_out)
 *   3. All operations go through this service
 */
class InventoryService
{
    // ══════════════════════════════════════
    //  STOCK QUERIES
    // ══════════════════════════════════════

    /**
     * Get current stock for a specific product + batch at a location.
     */
    public function getStock(int $productId, string $batchNo, string $locationType, int $locationId): float
    {
        $result = InventoryLedger::where('product_id', $productId)
            ->where('batch_no', $batchNo)
            ->where('location_type', $locationType)
            ->where('location_id', $locationId)
            ->selectRaw('COALESCE(SUM(qty_in), 0) - COALESCE(SUM(qty_out), 0) as stock')
            ->value('stock');

        return (float) ($result ?? 0);
    }

    /**
     * Get all stock at a location (grouped by product + batch).
     * Returns: [{product_id, batch_no, expiry_date, mrp, stock}]
     */
    public function getLocationStock(string $locationType, int $locationId): Collection
    {
        return InventoryLedger::where('location_type', $locationType)
            ->where('location_id', $locationId)
            ->groupBy('product_id', 'batch_no', 'expiry_date', 'mrp')
            ->selectRaw('
                product_id, batch_no, expiry_date, mrp,
                COALESCE(SUM(qty_in), 0) - COALESCE(SUM(qty_out), 0) as stock
            ')
            ->havingRaw('COALESCE(SUM(qty_in), 0) - COALESCE(SUM(qty_out), 0) > 0')
            ->get();
    }

    /**
     * Get product stock across ALL batches at a location.
     */
    public function getProductStockAtLocation(int $productId, string $locationType, int $locationId): Collection
    {
        return InventoryLedger::where('product_id', $productId)
            ->where('location_type', $locationType)
            ->where('location_id', $locationId)
            ->groupBy('batch_no', 'expiry_date', 'mrp')
            ->selectRaw('
                batch_no, expiry_date, mrp,
                COALESCE(SUM(qty_in), 0) - COALESCE(SUM(qty_out), 0) as stock
            ')
            ->havingRaw('COALESCE(SUM(qty_in), 0) - COALESCE(SUM(qty_out), 0) > 0')
            ->orderBy('expiry_date')
            ->get();
    }

    /**
     * Get batches expiring within N days at a location.
     */
    public function getExpiringBatches(int $days, string $locationType, int $locationId): Collection
    {
        return InventoryLedger::where('location_type', $locationType)
            ->where('location_id', $locationId)
            ->where('expiry_date', '<=', now()->addDays($days))
            ->where('expiry_date', '>', now())
            ->groupBy('product_id', 'batch_no', 'expiry_date', 'mrp')
            ->selectRaw('
                product_id, batch_no, expiry_date, mrp,
                COALESCE(SUM(qty_in), 0) - COALESCE(SUM(qty_out), 0) as stock
            ')
            ->havingRaw('COALESCE(SUM(qty_in), 0) - COALESCE(SUM(qty_out), 0) > 0')
            ->orderBy('expiry_date')
            ->get();
    }

    /**
     * Get already-expired batches at a location.
     */
    public function getExpiredBatches(string $locationType, int $locationId): Collection
    {
        return InventoryLedger::where('location_type', $locationType)
            ->where('location_id', $locationId)
            ->where('expiry_date', '<', now())
            ->groupBy('product_id', 'batch_no', 'expiry_date', 'mrp')
            ->selectRaw('
                product_id, batch_no, expiry_date, mrp,
                COALESCE(SUM(qty_in), 0) - COALESCE(SUM(qty_out), 0) as stock
            ')
            ->havingRaw('COALESCE(SUM(qty_in), 0) - COALESCE(SUM(qty_out), 0) > 0')
            ->get();
    }

    // ══════════════════════════════════════
    //  STOCK MUTATIONS
    // ══════════════════════════════════════

    /**
     * Record a PURCHASE — stock coming IN from supplier to warehouse.
     */
    public function recordPurchase(array $data): InventoryLedger
    {
        return InventoryLedger::create([
            'product_id' => $data['product_id'],
            'batch_no' => $data['batch_no'],
            'expiry_date' => $data['expiry_date'] ?? null,
            'mfg_date' => $data['mfg_date'] ?? null,
            'mrp' => $data['mrp'] ?? null,
            'location_type' => 'warehouse',
            'location_id' => 0, // HO warehouse
            'transaction_type' => 'PURCHASE',
            'reference_type' => 'purchase_invoice',
            'reference_id' => $data['reference_id'],
            'qty_in' => $data['qty'] + ($data['free_qty'] ?? 0),
            'qty_out' => 0,
            'rate' => $data['rate'] ?? null,
            'created_by' => $data['created_by'] ?? auth()->id(),
            'remarks' => $data['remarks'] ?? null,
        ]);
    }

    /**
     * Record a SALE — stock going OUT from franchisee to customer (POS).
     * Legacy: Sale_Management → submitDataAndGetReciept → direct tbl_stock UPDATE
     */
    public function recordSale(array $data): InventoryLedger
    {
        return $this->deductStockIfAvailable(
            productId: (int) $data['product_id'],
            batchNo: (string) $data['batch_no'],
            locationType: 'franchisee',
            locationId: (int) $data['franchisee_id'],
            requiredQty: (float) $data['qty'],
            onSuccess: fn () => InventoryLedger::create([
                'product_id' => $data['product_id'],
                'batch_no' => $data['batch_no'],
                'expiry_date' => $data['expiry_date'] ?? null,
                'mrp' => $data['mrp'] ?? null,
                'location_type' => 'franchisee',
                'location_id' => $data['franchisee_id'],
                'transaction_type' => 'SALE',
                'reference_type' => 'sales_invoice',
                'reference_id' => $data['reference_id'],
                'qty_in' => 0,
                'qty_out' => $data['qty'],
                'rate' => $data['rate'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]),
            insufficientMessage: "Insufficient franchise stock for product {$data['product_id']}, batch {$data['batch_no']}."
        );
    }

    /**
     * Record a DISPATCH — stock moving from warehouse to franchisee.
     * Creates TWO ledger entries:
     *   1. qty_out from warehouse (HO)
     *   2. qty_in at franchisee location
     *
     * Legacy: ordereraccept_order → deducted from BOTH FMS + PharmaERP databases!
     */
    public function recordDispatch(array $data): array
    {
        return $this->deductStockIfAvailable(
            productId: (int) $data['product_id'],
            batchNo: (string) $data['batch_no'],
            locationType: 'warehouse',
            locationId: 0,
            requiredQty: (float) $data['qty'],
            onSuccess: function () use ($data) {
                $out = InventoryLedger::create([
                    'product_id' => $data['product_id'],
                    'batch_no' => $data['batch_no'],
                    'expiry_date' => $data['expiry_date'] ?? null,
                    'mfg_date' => $data['mfg_date'] ?? null,
                    'mrp' => $data['mrp'] ?? null,
                    'location_type' => 'warehouse',
                    'location_id' => 0,
                    'transaction_type' => 'DISPATCH',
                    'reference_type' => 'dist_order',
                    'reference_id' => $data['order_id'],
                    'qty_in' => 0,
                    'qty_out' => $data['qty'],
                    'rate' => $data['rate'] ?? null,
                    'created_by' => $data['created_by'] ?? auth()->id(),
                ]);

                $in = InventoryLedger::create([
                    'product_id' => $data['product_id'],
                    'batch_no' => $data['batch_no'],
                    'expiry_date' => $data['expiry_date'] ?? null,
                    'mfg_date' => $data['mfg_date'] ?? null,
                    'mrp' => $data['mrp'] ?? null,
                    'location_type' => 'franchisee',
                    'location_id' => $data['franchisee_id'],
                    'transaction_type' => 'RECEIVE',
                    'reference_type' => 'dist_order',
                    'reference_id' => $data['order_id'],
                    'qty_in' => $data['qty'],
                    'qty_out' => 0,
                    'rate' => $data['rate'] ?? null,
                    'created_by' => $data['created_by'] ?? auth()->id(),
                ]);

                return ['out' => $out, 'in' => $in];
            },
            insufficientMessage: "Insufficient warehouse stock for product {$data['product_id']}, batch {$data['batch_no']}."
        );
    }

    /**
     * Record a SALE RETURN — customer returns to franchisee.
     */
    public function recordSaleReturn(array $data): InventoryLedger
    {
        return InventoryLedger::create([
            'product_id' => $data['product_id'],
            'batch_no' => $data['batch_no'],
            'expiry_date' => $data['expiry_date'] ?? null,
            'mrp' => $data['mrp'] ?? null,
            'location_type' => 'franchisee',
            'location_id' => $data['franchisee_id'],
            'transaction_type' => 'RETURN_SALE',
            'reference_type' => 'sales_return',
            'reference_id' => $data['reference_id'],
            'qty_in' => $data['qty'], // Stock comes back
            'qty_out' => 0,
            'rate' => $data['rate'] ?? null,
            'created_by' => $data['created_by'] ?? auth()->id(),
        ]);
    }

    /**
     * Record a PURCHASE RETURN — HO returns to supplier.
     */
    public function recordPurchaseReturn(array $data): InventoryLedger
    {
        return $this->deductStockIfAvailable(
            productId: (int) $data['product_id'],
            batchNo: (string) $data['batch_no'],
            locationType: 'warehouse',
            locationId: 0,
            requiredQty: (float) $data['qty'],
            onSuccess: fn () => InventoryLedger::create([
                'product_id' => $data['product_id'],
                'batch_no' => $data['batch_no'],
                'expiry_date' => $data['expiry_date'] ?? null,
                'mrp' => $data['mrp'] ?? null,
                'location_type' => 'warehouse',
                'location_id' => 0,
                'transaction_type' => 'RETURN_PURCHASE',
                'reference_type' => 'purchase_return',
                'reference_id' => $data['reference_id'],
                'qty_in' => 0,
                'qty_out' => $data['qty'],
                'rate' => $data['rate'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]),
            insufficientMessage: "Insufficient warehouse stock for product {$data['product_id']}, batch {$data['batch_no']}."
        );
    }

    /**
     * Reverse a previously approved PURCHASE RETURN.
     * Uses an ADJUSTMENT entry, but keeps the original purchase return reference
     * so the audit trail remains tied to the source document.
     */
    public function recordPurchaseReturnReversal(array $data): InventoryLedger
    {
        return InventoryLedger::create([
            'product_id' => $data['product_id'],
            'batch_no' => $data['batch_no'],
            'expiry_date' => $data['expiry_date'] ?? null,
            'mrp' => $data['mrp'] ?? null,
            'location_type' => 'warehouse',
            'location_id' => 0,
            'transaction_type' => 'ADJUSTMENT',
            'reference_type' => 'purchase_return',
            'reference_id' => $data['reference_id'],
            'qty_in' => $data['qty'],
            'qty_out' => 0,
            'rate' => $data['rate'] ?? null,
            'created_by' => $data['created_by'] ?? auth()->id(),
            'remarks' => $data['remarks'] ?? 'Purchase return reversal',
        ]);
    }

    /**
     * Record a MANUAL ADJUSTMENT.
     */
    public function recordAdjustment(array $data): InventoryLedger
    {
        if (empty($data['qty']) || (float)$data['qty'] === 0.0) {
            throw new \InvalidArgumentException('Adjustment quantity must be non-zero');
        }
        $isPositive = (float) $data['qty'] > 0;
        $createAdjustment = fn () => InventoryLedger::create([
            'product_id' => $data['product_id'],
            'batch_no' => $data['batch_no'],
            'expiry_date' => $data['expiry_date'] ?? null,
            'mrp' => $data['mrp'] ?? null,
            'location_type' => $data['location_type'],
            'location_id' => $data['location_id'],
            'transaction_type' => 'ADJUSTMENT',
            'reference_type' => 'manual',
            'reference_id' => null,
            'qty_in' => $isPositive ? abs($data['qty']) : 0,
            'qty_out' => !$isPositive ? abs($data['qty']) : 0,
            'rate' => $data['rate'] ?? null,
            'created_by' => $data['created_by'] ?? auth()->id(),
            'remarks' => $data['remarks'] ?? 'Manual stock adjustment',
        ]);

        if ($isPositive) {
            return $createAdjustment();
        }

        return $this->deductStockIfAvailable(
            productId: (int) $data['product_id'],
            batchNo: (string) $data['batch_no'],
            locationType: (string) $data['location_type'],
            locationId: (int) $data['location_id'],
            requiredQty: abs((float) $data['qty']),
            onSuccess: $createAdjustment,
            insufficientMessage: "Insufficient stock for negative adjustment on product {$data['product_id']}, batch {$data['batch_no']}."
        );
    }

    // ══════════════════════════════════════
    //  VALIDATION
    // ══════════════════════════════════════

    /**
     * Check if sufficient stock exists before allowing deduction.
     */
    public function hasSufficientStock(
        int $productId, string $batchNo,
        string $locationType, int $locationId,
        float $requiredQty
    ): bool {
        return $this->getStock($productId, $batchNo, $locationType, $locationId) >= $requiredQty;
    }

    /**
     * Atomically lock a stock bucket, verify available stock, and perform a
     * stock-consuming write. This prevents read-then-write races.
     */
    public function deductStockIfAvailable(
        int $productId,
        string $batchNo,
        string $locationType,
        int $locationId,
        float $requiredQty,
        callable $onSuccess,
        ?string $insufficientMessage = null
    ): mixed {
        if ($requiredQty <= 0) {
            throw new \InvalidArgumentException('Required quantity must be greater than zero.');
        }

        return DB::transaction(function () use (
            $productId,
            $batchNo,
            $locationType,
            $locationId,
            $requiredQty,
            $onSuccess,
            $insufficientMessage
        ) {
            $availableStock = (float) (InventoryLedger::query()
                ->where('product_id', $productId)
                ->where('batch_no', $batchNo)
                ->where('location_type', $locationType)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->selectRaw('COALESCE(SUM(qty_in), 0) - COALESCE(SUM(qty_out), 0) as stock')
                ->value('stock') ?? 0);

            if ($availableStock + 0.0001 < $requiredQty) {
                throw new \DomainException($insufficientMessage ?? "Insufficient stock for product {$productId}, batch {$batchNo}.");
            }

            return $onSuccess();
        });
    }
}
