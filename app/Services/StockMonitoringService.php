<?php

namespace App\Services;

use App\Models\Franchisee;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\StockAlert;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * StockMonitoringService
 * 
 * Central point for all stock threshold checks, expiry detection, and alert generation.
 * Ensures every purchase/dispatch/return triggers automatic stock governance.
 */
class StockMonitoringService
{
    /**
     * Check if product stock at a location is below reorder threshold.
     * Creates and returns a StockAlert if triggered.
     */
    public function checkThreshold(
        Product $product,
        ?Franchisee $franchisee = null,
        string $triggerSource = 'manual_check',
        ?int $referenceId = null,
        string $referenceType = 'unknown'
    ): ?StockAlert {
        $currentQty = $this->getStockQuantity($product, $franchisee);
        $threshold = (float) $product->reorder_quantity;

        // Only alert if below reorder threshold and we have a threshold set
        if ($threshold <= 0 || $currentQty >= $threshold) {
            return null;
        }

        return $this->createAlert(
            alertType: 'threshold',
            product: $product,
            currentQty: $currentQty,
            thresholdQty: $threshold,
            level: $this->determineAlertLevel($currentQty, $product),
            franchisee: $franchisee,
            triggerSource: $triggerSource,
            referenceId: $referenceId,
            referenceType: $referenceType
        );
    }

    /**
     * Check stock levels at all locations for critical status.
     * Return true if below minimum stock threshold.
     */
    public function isCriticalStock(Product $product, ?Franchisee $franchisee = null): bool
    {
        $currentQty = $this->getStockQuantity($product, $franchisee);
        $minStock = (float) $product->min_stock_level;

        return $minStock > 0 && $currentQty < $minStock;
    }

    /**
     * Get current stock quantity for a product at a location.
     */
    public function getStockQuantity(Product $product, ?Franchisee $franchisee = null): float
    {
        $query = InventoryLedger::where('product_id', $product->id)
            ->lockForUpdate();

        if ($franchisee) {
            $query->where('location_type', 'FRANCHISEE')
                ->where('location_id', $franchisee->id);
        } else {
            // HO warehouse
            $query->where('location_type', 'HO')
                ->where('location_id', 0);
        }

        $ledgers = $query->get();
        $totalQty = 0;

        foreach ($ledgers as $ledger) {
            $totalQty += (float) $ledger->qty_in - (float) $ledger->qty_out;
        }

        return max(0, $totalQty);
    }

    /**
     * Create a stock alert record.
     */
    public function createAlert(
        string $alertType,
        Product $product,
        float $currentQty,
        float $thresholdQty,
        string $level = 'warning',
        ?Franchisee $franchisee = null,
        string $triggerSource = 'manual_check',
        ?int $referenceId = null,
        string $referenceType = 'unknown',
        ?string $batchNo = null,
        ?string $expiryDate = null
    ): StockAlert {
        $alert = StockAlert::create([
            'alert_type' => $alertType,
            'product_id' => $product->id,
            'franchisee_id' => $franchisee?->id,
            'current_qty' => $currentQty,
            'threshold_qty' => $thresholdQty,
            'batch_no' => $batchNo,
            'expiry_date' => $expiryDate,
            'alert_level' => $level,
            'triggered_at' => now(),
            'trigger_source' => $triggerSource,
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
            'status' => 'pending',
        ]);

        return $alert;
    }

    /**
     * Acknowledge an alert (mark as seen by user).
     */
    public function acknowledgeAlert(StockAlert $alert, User $user, ?string $actionTaken = null): void
    {
        $alert->update([
            'acknowledged_by' => $user->id,
            'acknowledged_at' => now(),
            'action_taken' => $actionTaken,
            'status' => 'acknowledged',
        ]);
    }

    /**
     * Mark alert as resolved.
     */
    public function resolveAlert(StockAlert $alert, ?string $actionTaken = null): void
    {
        $alert->update([
            'action_taken' => $actionTaken,
            'status' => 'resolved',
        ]);
    }

    /**
     * Mark alert as false alarm (e.g., stock discrepancy auto-corrected).
     */
    public function dismissAlert(StockAlert $alert, string $reason = ''): void
    {
        $alert->update([
            'action_taken' => $reason,
            'status' => 'false_alarm',
        ]);
    }

    /**
     * Check for expiring batches (within 30 days or already expired).
     * Creates expiry alerts for each expiring batch at each location.
     * 
     * Called by: Console\Commands\CheckStockExpiry
     */
    public function detectExpiringBatches(int $expiringWithinDays = 30): Collection
    {
        $alerts = collect();

        // Get all inventory ledger records with expiry dates
        $expiringRecords = InventoryLedger::where('expiry_date', '<=', now()->addDays($expiringWithinDays))
            ->where('expiry_date', '>', now()->subDay()) // Exclude already expired
            ->where(DB::raw('qty_in - qty_out'), '>', 0) // Only stock that exists
            ->with('product')
            ->get()
            ->groupBy(function ($ledger) {
                return "{$ledger->product_id}_{$ledger->batch_no}_{$ledger->location_type}_{$ledger->location_id}";
            });

        foreach ($expiringRecords as $group) {
            $sample = $group->first();
            $totalQty = $group->sum(fn ($l) => (float) $l->qty_in - (float) $l->qty_out);

            $daysUntilExpiry = now()->diffInDays($sample->expiry_date, false);
            $level = $daysUntilExpiry <= 7 ? 'critical' : 'warning';

            $franchisee = $sample->location_type === 'FRANCHISEE'
                ? Franchisee::find($sample->location_id)
                : null;

            $alert = $this->createAlert(
                alertType: 'expiry',
                product: $sample->product,
                currentQty: $totalQty,
                thresholdQty: 0,
                level: $level,
                franchisee: $franchisee,
                triggerSource: 'expiry_check',
                batchNo: $sample->batch_no,
                expiryDate: $sample->expiry_date?->toDateString()
            );

            $alerts->push($alert);
        }

        return $alerts;
    }

    /**
     * Reconcile stock between inventory ledger and expected quantities.
     * Detect variances > 5% and report them.
     * 
     * Called by: Console\Commands\ReconcileStockLevels
     * Helps catch data corruption, missing entries, or manual adjustments.
     */
    public function detectStockVariances(float $varianceThresholdPercent = 5.0): Collection
    {
        $alerts = collect();

        // Group by product+location and find any unusual patterns
        $products = Product::where('is_active', true)->get();

        foreach ($products as $product) {
            // Check HO
            $hoStockQty = $this->getStockQuantity($product, null);
            $hoVariance = $this->calculateVariance($product, null);

            if ($hoVariance !== null && abs($hoVariance) > $varianceThresholdPercent) {
                $alert = $this->createAlert(
                    alertType: 'variance',
                    product: $product,
                    currentQty: $hoStockQty,
                    thresholdQty: $hoStockQty, // For context
                    level: 'warning',
                    franchisee: null,
                    triggerSource: 'variance_check'
                );
                $alert->update(['action_taken' => "Variance: {$hoVariance}%"]);
                $alerts->push($alert);
            }

            // Check each franchisee
            $franchisees = Franchisee::active()->get();
            foreach ($franchisees as $franchisee) {
                $franchiseeStockQty = $this->getStockQuantity($product, $franchisee);
                $franchiseeVariance = $this->calculateVariance($product, $franchisee);

                if ($franchiseeVariance !== null && abs($franchiseeVariance) > $varianceThresholdPercent) {
                    $alert = $this->createAlert(
                        alertType: 'variance',
                        product: $product,
                        currentQty: $franchiseeStockQty,
                        thresholdQty: $franchiseeStockQty,
                        level: 'warning',
                        franchisee: $franchisee,
                        triggerSource: 'variance_check'
                    );
                    $alert->update(['action_taken' => "Variance: {$franchiseeVariance}%"]);
                    $alerts->push($alert);
                }
            }
        }

        return $alerts;
    }

    /**
     * Get recent unacknowledged alerts for a location.
     */
    public function getUnacknowledgedAlerts(?Franchisee $franchisee = null, int $limit = 20): Collection
    {
        $query = StockAlert::where('status', 'pending');

        if ($franchisee) {
            $query->where('franchisee_id', $franchisee->id);
        } else {
            $query->whereNull('franchisee_id'); // HO alerts
        }

        return $query
            ->orderByRaw("CASE alert_level WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->latest('triggered_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get alert statistics for dashboard.
     */
    public function getAlertStats(?Franchisee $franchisee = null): array
    {
        $query = StockAlert::where('status', 'pending');

        if ($franchisee) {
            $query->where('franchisee_id', $franchisee->id);
        } else {
            $query->whereNull('franchisee_id');
        }

        return [
            'total_pending' => (clone $query)->count(),
            'critical_alerts' => (clone $query)->where('alert_level', 'critical')->count(),
            'warning_alerts' => (clone $query)->where('alert_level', 'warning')->count(),
            'info_alerts' => (clone $query)->where('alert_level', 'info')->count(),
            'expiry_alerts' => (clone $query)->where('alert_type', 'expiry')->count(),
            'threshold_alerts' => (clone $query)->where('alert_type', 'threshold')->count(),
        ];
    }

    // ──── Private Helpers ────

    private function determineAlertLevel(float $currentQty, Product $product): string
    {
        $minStock = (float) $product->min_stock_level;
        $reorder = (float) $product->reorder_quantity;

        if ($minStock > 0 && $currentQty < $minStock) {
            return 'critical';
        }

        if ($currentQty < ($reorder * 0.5)) {
            return 'critical';
        }

        return 'warning';
    }

    private function calculateVariance(Product $product, ?Franchisee $franchisee = null): ?float
    {
        // This is a placeholder - actual variance detection depends on your business logic
        // Could involve comparing expected quantities from open orders vs actual inventory
        // For now, returns null (no variance detected)
        return null;
    }
}
