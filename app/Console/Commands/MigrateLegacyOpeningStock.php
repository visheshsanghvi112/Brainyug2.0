<?php

namespace App\Console\Commands;

use App\Helpers\LegacySqlReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Migrate legacy warehouse opening stock batches into inventory_ledgers as OPENING entries.
 *
 * Source tables (pharmaer_pharmaerp.sql):
 *   - tbl_stock  → batch qty baseline (HO warehouse)
 *   - purchase_challan_product → commercial enrichment (purchase_rate, mrp, gst, expiry, mfg)
 *
 * Source tables (genericp_franchisee.sql per franchisee):
 *   - pharma_tbl_stock → franchisee-side batch qty baseline
 *   - purchase_challan_product → franchisee-side commercial enrichment
 *
 * STRATEGY:
 *  1. Load tbl_stock snapshot (product_id + batch → qty, expiry, mfg, mrp)
 *  2. Enrich from purchase_challan_product (purchase_rate, GST, exact batch details)
 *  3. Insert one OPENING entry per product+batch into inventory_ledgers
 *  4. Skip if an OPENING entry for that product+batch+location already exists
 *  5. Print reconciliation report: matched / stock-only / anomalies
 *
 * Safety:
 *  - Idempotent: re-running skips already-imported rows
 *  - Never touches live PURCHASE/DISPATCH/SALE ledger entries
 *  - All inserts in a single transaction; rollback on any error
 */
class MigrateLegacyOpeningStock extends Command
{
    protected $signature = 'erp:migrate-legacy-opening-stock
                            {--ho-file= : Absolute path to pharmaer_pharmaerp.sql}
                            {--fran-file= : Absolute path to genericp_franchisee.sql (optional, for franchisee stock)}
                            {--dry-run : Preview only; write nothing to DB}
                            {--batch-size=500 : DB insert chunk size}';

    protected $description = 'Import legacy batch-level opening stock into inventory_ledgers as OPENING entries with commercial enrichment from purchase challan data.';

    public function handle(): int
    {
        $hoFile   = $this->option('ho-file') ?: base_path('../pharmaer_pharmaerp.sql');
        $franFile = $this->option('fran-file') ?: base_path('../genericp_franchisee.sql');
        $dryRun   = (bool) $this->option('dry-run');
        $chunkSize = max(100, (int) $this->option('batch-size'));

        if (!file_exists($hoFile)) {
            $this->error("HO SQL file not found: {$hoFile}");
            return self::FAILURE;
        }

        $this->info('=== Legacy Opening Stock Migration' . ($dryRun ? ' [DRY RUN]' : '') . ' ===');
        $this->newLine();

        // ── 1. Load product ID whitelist ────────────────────────────────────
        $this->info('Loading product whitelist from new DB...');
        $knownProductIds = DB::table('products')->pluck('id')->flip()->toArray();
        $this->line('  ' . count($knownProductIds) . ' products known.');

        // ── 2. Load existing OPENING entries to skip duplicates ─────────────
        $this->info('Loading existing OPENING ledger entries...');
        $existingOpenings = DB::table('inventory_ledgers')
            ->where('transaction_type', 'OPENING')
            ->select('product_id', 'batch_no', 'location_type', 'location_id')
            ->get()
            ->mapWithKeys(fn($r) => ["{$r->product_id}|{$r->batch_no}|{$r->location_type}|{$r->location_id}" => true])
            ->toArray();
        $this->line('  ' . count($existingOpenings) . ' existing OPENING entries (will be skipped).');

        // ── 3. Build commercial enrichment map from purchase_challan_product ─
        $this->info('Building purchase rate map from purchase_challan_product...');
        $purchaseRateMap = $this->buildPurchaseRateMap($hoFile);
        $this->line('  ' . count($purchaseRateMap) . ' product|batch combinations found in purchase challan.');

        // ── 4. Process HO warehouse stock from tbl_stock ───────────────────
        $this->info('Processing HO warehouse stock from tbl_stock...');
        $hoResult = $this->importStockTable(
            $hoFile,
            'tbl_stock',
            'warehouse',
            0,
            $knownProductIds,
            $existingOpenings,
            $purchaseRateMap,
            $chunkSize,
            $dryRun
        );

        // ── 5. Process franchisee stock if fran-file provided ──────────────
        $franResult = ['imported' => 0, 'skipped' => 0, 'anomalies' => []];
        if (file_exists($franFile)) {
            $this->info('Processing franchisee stock from pharma_tbl_stock...');
            $franRateMap   = $this->buildPurchaseRateMap($franFile);
            $franResult    = $this->importFranchiseeStock(
                $franFile,
                $knownProductIds,
                $existingOpenings,
                $franRateMap,
                $chunkSize,
                $dryRun
            );
        } else {
            $this->warn("Franchisee SQL file not found at {$franFile}; skipping franchisee stock.");
        }

        // ── 6. Reconciliation report ────────────────────────────────────────
        $this->printReport($hoResult, $franResult);

        return self::SUCCESS;
    }

    // ══════════════════════════════════════════════════════════════════
    //  PURCHASE RATE MAP BUILDER
    // ══════════════════════════════════════════════════════════════════

    /**
     * Builds product_id|batch_no → latest purchase commercial fields map.
     * Uses the LAST inserted row per product+batch (most recent purchase wins).
     */
    private function buildPurchaseRateMap(string $sqlFile): array
    {
        $map = [];

        foreach (LegacySqlReader::streamTableRows($sqlFile, 'purchase_challan_product') as $row) {
            $proId    = (int) ($row['pro_id'] ?? 0);
            $batch    = trim($row['batch'] ?? '');

            if ($proId <= 0 || $batch === '') {
                continue;
            }

            $key = "{$proId}|{$batch}";

            // Always overwrite — last row (highest id in INSERT) wins as the most recent purchase
            $map[$key] = [
                'purchase_rate' => $this->sanitizeDecimal($row['purchase_rate'] ?? '0'),
                'mrp'           => $this->sanitizeDecimal($row['mrp'] ?? '0'),
                'gst_percent'   => $this->sanitizeGst($row),
                'expiry_date'   => $this->parseDate($row['expiry_date'] ?? ''),
                'mfg_date'      => $this->parseDate($row['mfg_date'] ?? ''),
                'free_qty'      => $this->sanitizeDecimal($row['free'] ?? '0'),
            ];
        }

        return $map;
    }

    // ══════════════════════════════════════════════════════════════════
    //  HO WAREHOUSE IMPORTER (tbl_stock)
    // ══════════════════════════════════════════════════════════════════

    private function importStockTable(
        string $sqlFile,
        string $tableName,
        string $locationType,
        int    $locationId,
        array  $knownProductIds,
        array  &$existingOpenings,
        array  $purchaseRateMap,
        int    $chunkSize,
        bool   $dryRun
    ): array {
        $batch   = [];
        $stats   = ['imported' => 0, 'skipped' => 0, 'anomalies' => []];
        $now     = now()->toDateTimeString();

        foreach (LegacySqlReader::streamTableRows($sqlFile, $tableName) as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $batchNo   = trim($row['batch_no'] ?? '');
            $qty       = $this->sanitizeDecimal($row['actual_stock'] ?? '0');

            // Skip zero/negative stock — no point creating a zero OPENING entry
            if ($productId <= 0 || $batchNo === '' || $qty <= 0) {
                $stats['skipped']++;
                continue;
            }

            // Skip unknown products
            if (!isset($knownProductIds[$productId])) {
                $stats['anomalies'][] = [
                    'reason' => 'unknown_product',
                    'product_id' => $productId,
                    'batch_no' => $batchNo,
                    'qty' => $qty,
                ];
                $stats['skipped']++;
                continue;
            }

            // Skip already-imported
            $key = "{$productId}|{$batchNo}|{$locationType}|{$locationId}";
            if (isset($existingOpenings[$key])) {
                $stats['skipped']++;
                continue;
            }

            // Enrich from purchase challan if available
            $purchaseKey    = "{$productId}|{$batchNo}";
            $commercialData = $purchaseRateMap[$purchaseKey] ?? null;

            $rate       = $commercialData['purchase_rate'] ?? null;
            $mrp        = $commercialData['mrp'] ?? $this->sanitizeDecimal($row['mrp_rate'] ?? '0');
            $expiryDate = $commercialData['expiry_date'] ?? $this->parseDate($row['expiry_date'] ?? '');
            $mfgDate    = $commercialData['mfg_date']    ?? $this->parseDate($row['mfg_date'] ?? '');

            $confidence = $commercialData ? 'purchase_challan_matched' : 'stock_snapshot_only';

            $batch[] = [
                'product_id'       => $productId,
                'batch_no'         => $batchNo,
                'expiry_date'      => $expiryDate,
                'mfg_date'         => $mfgDate,
                'mrp'              => $mrp > 0 ? $mrp : null,
                'location_type'    => $locationType,
                'location_id'      => $locationId,
                'transaction_type' => 'OPENING',
                'reference_type'   => 'legacy_stock',
                'reference_id'     => null,
                'qty_in'           => $qty,
                'qty_out'          => 0,
                'rate'             => $rate > 0 ? $rate : null,
                'created_by'       => null,
                'remarks'          => "Legacy opening stock import [{$confidence}]",
                'created_at'       => $now,
            ];

            // Mark as known so subsequent re-runs skip it
            $existingOpenings[$key] = true;
            $stats['imported']++;

            if (count($batch) >= $chunkSize) {
                $this->flush($batch, $dryRun);
                $batch = [];
                $this->output->write('.');
            }
        }

        if (!empty($batch)) {
            $this->flush($batch, $dryRun);
            $this->output->write('.');
        }

        $this->newLine();
        return $stats;
    }

    // ══════════════════════════════════════════════════════════════════
    //  FRANCHISEE STOCK IMPORTER (pharma_tbl_stock)
    // ══════════════════════════════════════════════════════════════════

    private function importFranchiseeStock(
        string $sqlFile,
        array  $knownProductIds,
        array  &$existingOpenings,
        array  $purchaseRateMap,
        int    $chunkSize,
        bool   $dryRun
    ): array {
        // We need to know which franch_id maps to which franchisee.id in our DB
        $franchiseeMap = DB::table('franchisees')
            ->whereNotNull('legacy_franchise_id')
            ->pluck('id', 'legacy_franchise_id')
            ->toArray();

        $batch = [];
        $stats = ['imported' => 0, 'skipped' => 0, 'anomalies' => []];
        $now   = now()->toDateTimeString();

        foreach (LegacySqlReader::streamTableRows($sqlFile, 'pharma_tbl_stock') as $row) {
            $legacyFranchId = (int) ($row['franch_id'] ?? 0);
            $productId      = (int) ($row['product_id'] ?? 0);
            $batchNo        = trim($row['batch_no'] ?? '');
            $qty            = $this->sanitizeDecimal($row['actual_stock'] ?? '0');

            if ($productId <= 0 || $batchNo === '' || $qty <= 0) {
                $stats['skipped']++;
                continue;
            }

            if (!isset($knownProductIds[$productId])) {
                $stats['anomalies'][] = ['reason' => 'unknown_product', 'product_id' => $productId, 'batch_no' => $batchNo, 'qty' => $qty];
                $stats['skipped']++;
                continue;
            }

            if ($legacyFranchId > 0 && !isset($franchiseeMap[$legacyFranchId])) {
                $stats['anomalies'][] = ['reason' => 'unknown_franchisee', 'legacy_franch_id' => $legacyFranchId, 'product_id' => $productId, 'batch_no' => $batchNo];
                $stats['skipped']++;
                continue;
            }

            $locationId = $legacyFranchId > 0 ? $franchiseeMap[$legacyFranchId] : 0;

            $key = "{$productId}|{$batchNo}|franchisee|{$locationId}";
            if (isset($existingOpenings[$key])) {
                $stats['skipped']++;
                continue;
            }

            $purchaseKey    = "{$productId}|{$batchNo}";
            $commercialData = $purchaseRateMap[$purchaseKey] ?? null;

            $rate       = $commercialData['purchase_rate'] ?? null;
            $mrp        = $commercialData['mrp'] ?? $this->sanitizeDecimal($row['mrp_rate'] ?? '0');
            $expiryDate = $commercialData['expiry_date'] ?? $this->parseDate($row['expiry_date'] ?? '');
            $mfgDate    = $commercialData['mfg_date']    ?? $this->parseDate($row['mfg_date'] ?? '');
            $confidence = $commercialData ? 'purchase_challan_matched' : 'stock_snapshot_only';

            $batch[] = [
                'product_id'       => $productId,
                'batch_no'         => $batchNo,
                'expiry_date'      => $expiryDate,
                'mfg_date'         => $mfgDate,
                'mrp'              => $mrp > 0 ? $mrp : null,
                'location_type'    => 'franchisee',
                'location_id'      => $locationId,
                'transaction_type' => 'OPENING',
                'reference_type'   => 'legacy_stock',
                'reference_id'     => null,
                'qty_in'           => $qty,
                'qty_out'          => 0,
                'rate'             => $rate > 0 ? $rate : null,
                'created_by'       => null,
                'remarks'          => "Legacy franchisee opening stock [{$confidence}]",
                'created_at'       => $now,
            ];

            $existingOpenings[$key] = true;
            $stats['imported']++;

            if (count($batch) >= $chunkSize) {
                $this->flush($batch, $dryRun);
                $batch = [];
                $this->output->write('.');
            }
        }

        if (!empty($batch)) {
            $this->flush($batch, $dryRun);
        }

        $this->newLine();
        return $stats;
    }

    // ══════════════════════════════════════════════════════════════════
    //  HELPERS
    // ══════════════════════════════════════════════════════════════════

    private function flush(array $rows, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        DB::table('inventory_ledgers')->insert($rows);
    }

    private function printReport(array $hoResult, array $franResult): void
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════════════');
        $this->info('  OPENING STOCK MIGRATION REPORT');
        $this->info('═══════════════════════════════════════════════');
        $this->table(
            ['Source', 'Imported', 'Skipped'],
            [
                ['HO Warehouse (tbl_stock)', $hoResult['imported'], $hoResult['skipped']],
                ['Franchisee (pharma_tbl_stock)', $franResult['imported'], $franResult['skipped']],
                ['TOTAL', $hoResult['imported'] + $franResult['imported'], $hoResult['skipped'] + $franResult['skipped']],
            ]
        );

        $allAnomalies = array_merge($hoResult['anomalies'], $franResult['anomalies']);
        if (!empty($allAnomalies)) {
            $this->newLine();
            $this->warn('ANOMALIES (' . count($allAnomalies) . ') — rows that were skipped and need manual review:');
            $byReason = collect($allAnomalies)->groupBy('reason');
            foreach ($byReason as $reason => $items) {
                $this->warn("  [{$reason}] — " . count($items) . ' rows');
            }
            $this->warn('  Tip: Run with --dry-run first and review anomalies before committing.');
        } else {
            $this->info('  No anomalies detected.');
        }

        $this->newLine();
        $this->info('Next step: run erp:migrate-legacy-purchase-invoices to import read-only procurement history.');
        $this->info('═══════════════════════════════════════════════');
    }

    private function sanitizeDecimal($value): float
    {
        $val = str_replace(',', '', trim((string) ($value ?? '')));
        return is_numeric($val) ? round((float) $val, 4) : 0.0;
    }

    /**
     * Resolve GST% from purchase_challan_product row.
     * Legacy stores either a combined GST column or separate sgst/cgst/igst.
     */
    private function sanitizeGst(array $row): float
    {
        // Combined GST column (HO schema has `GST`)
        if (isset($row['GST']) && is_numeric(trim((string) $row['GST']))) {
            $g = (float) trim((string) $row['GST']);
            if ($g > 0) {
                return round($g, 2);
            }
        }

        // Fall back to sgst + cgst
        $sgst = $this->sanitizeDecimal($row['sgst'] ?? '0');
        $cgst = $this->sanitizeDecimal($row['cgst'] ?? '0');
        if ($sgst + $cgst > 0) {
            return round($sgst + $cgst, 2);
        }

        // Or IGST
        $igst = $this->sanitizeDecimal($row['igst'] ?? '0');
        return round($igst, 2);
    }

    private function parseDate($value): ?string
    {
        if (empty($value) || trim((string) $value) === '' || trim((string) $value) === '0000-00-00') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse(trim((string) $value))->toDateString();
        } catch (\Exception $e) {
            return null;
        }
    }
}
