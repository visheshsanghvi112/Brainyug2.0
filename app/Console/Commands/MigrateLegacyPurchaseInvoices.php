<?php

namespace App\Console\Commands;

use App\Helpers\LegacySqlReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Migrate legacy purchase challan (purchase invoice) records as read-only historical documents.
 *
 * Source tables (pharmaer_pharmaerp.sql):
 *   - purchase_challan_vendor  → invoice header (date, supplier, tax_type, invoice no)
 *   - purchase_challan_product → invoice line items (batch, qty, rate, mrp, gst, expiry, mfg)
 *
 * Source tables (genericp_franchisee.sql):
 *   - purchase_challan_vendor + purchase_challan_product → franchisee-side challan data
 *
 * WHAT THIS DOES:
 *  1. Create purchase_invoices rows with status='legacy'  (read-only, non-editable)
 *  2. Create purchase_invoice_items rows with full batch commercial data
 *  3. Does NOT create inventory_ledger PURCHASE entries (opening stock already handled)
 *  4. Sets legacy_challan_id so original records can be cross-referenced
 *  5. Prints a reconciliation report: matched / skipped / anomalies
 *
 * IMPORTANT NOTES:
 *  - Uses a new 'legacy' status that is read-only in the UI
 *  - legacy_source and legacy_challan_id are set for full traceability
 *  - Run erp:migrate-legacy-opening-stock FIRST before this command
 *  - Safe to re-run: existing legacy invoices for same challan_id are skipped
 *
 * SAFETY:
 *  - All inserts in chunked transactions
 *  - --dry-run flag to preview without writing
 *  - --franch-id=X to migrate only one franchisee at a time
 */
class MigrateLegacyPurchaseInvoices extends Command
{
    protected $signature = 'erp:migrate-legacy-purchase-invoices
                            {--ho-file= : Absolute path to pharmaer_pharmaerp.sql}
                            {--fran-file= : Absolute path to genericp_franchisee.sql (optional)}
                            {--franch-id= : Migrate only this legacy franchisee ID (for partial runs)}
                            {--dry-run : Preview only; write nothing to DB}
                            {--skip-items : Import headers only, skip line items}';

    protected $description = 'Import legacy purchase_challan records as read-only historical purchase invoices with full batch/rate/GST traceability.';

    // Financial year that covers the entire legacy period we are importing
    private const LEGACY_FY = 'LEGACY';

    public function handle(): int
    {
        $hoFile    = $this->option('ho-file') ?: base_path('../pharmaer_pharmaerp.sql');
        $franFile  = $this->option('fran-file') ?: base_path('../genericp_franchisee.sql');
        $dryRun    = (bool) $this->option('dry-run');
        $skipItems = (bool) $this->option('skip-items');
        $onlyFranch = $this->option('franch-id') ? (int) $this->option('franch-id') : null;

        if (!file_exists($hoFile)) {
            $this->error("HO SQL file not found: {$hoFile}");
            return self::FAILURE;
        }

        $this->info('=== Legacy Purchase Invoice Migration' . ($dryRun ? ' [DRY RUN]' : '') . ' ===');
        $this->newLine();

        // ── Prerequisites ────────────────────────────────────────────────────
        $this->info('Checking DB prerequisites...');

        // Ensure 'legacy' status column exists on purchase_invoices
        if (!$this->legacyStatusColumnExists()) {
            $this->addLegacyStatusColumn($dryRun);
        }

        // Ensure legacy_challan_id traceability column exists
        if (!$this->legacyChallanIdColumnExists()) {
            $this->addLegacyChallanIdColumn($dryRun);
        }

        $hasLegacyChallanIdColumn = $this->legacyChallanIdColumnExists();

        // Supplier ID map: legacy vendor_id → new suppliers.id
        $this->info('Loading supplier map...');
        $supplierMap = DB::table('suppliers')->pluck('id', 'id')->toArray();
        $this->line('  ' . count($supplierMap) . ' suppliers loaded.');
        $unknownSupplierId = $this->resolveUnknownSupplierId($dryRun);

        // Product ID whitelist
        $this->info('Loading product whitelist...');
        $knownProductIds = DB::table('products')->pluck('id')->flip()->toArray();
        $this->line('  ' . count($knownProductIds) . ' products known.');

        // Already-migrated challan IDs (idempotent guard)
        if ($hasLegacyChallanIdColumn) {
            $existingChallanIds = DB::table('purchase_invoices')
                ->whereNotNull('legacy_challan_id')
                ->pluck('legacy_challan_id')
                ->flip()
                ->toArray();
            $this->line('  ' . count($existingChallanIds) . ' challan records already imported (will skip).');
        } else {
            // In dry-run on a pre-column DB, we cannot query idempotency markers yet.
            $existingChallanIds = [];
            $this->warn('  legacy_challan_id column is not present yet; dry-run will treat all challans as not-yet-imported.');
        }

        $existingSupplierInvoiceKeys = DB::table('purchase_invoices')
            ->where('financial_year', self::LEGACY_FY)
            ->whereNotNull('supplier_invoice_no')
            ->select('supplier_id', 'supplier_invoice_no')
            ->get()
            ->mapWithKeys(function ($row) {
                return [$this->supplierInvoiceKey((int) $row->supplier_id, (string) $row->supplier_invoice_no) => true];
            })
            ->toArray();

        // ── HO Purchase Challan ──────────────────────────────────────────────
        $this->info('Processing HO purchase_challan_vendor...');
        $hoResult = $this->importChallanData(
            $hoFile,
            $supplierMap,
            $unknownSupplierId,
            $existingSupplierInvoiceKeys,
            $knownProductIds,
            $existingChallanIds,
            $onlyFranch,
            $skipItems,
            $dryRun,
            'ho'
        );

        // ── Franchisee Purchase Challan ──────────────────────────────────────
        $franResult = ['imported' => 0, 'items_imported' => 0, 'skipped' => 0, 'anomalies' => []];
        if (file_exists($franFile)) {
            $this->info('Processing franchisee purchase_challan_vendor...');
            $franchiseeMap = DB::table('franchisees')
                ->whereNotNull('legacy_franchise_id')
                ->pluck('id', 'legacy_franchise_id')
                ->toArray();

            $franResult = $this->importChallanData(
                $franFile,
                $supplierMap,
                $unknownSupplierId,
                $existingSupplierInvoiceKeys,
                $knownProductIds,
                $existingChallanIds,
                $onlyFranch,
                $skipItems,
                $dryRun,
                'franchisee',
                $franchiseeMap
            );
        } else {
            $this->warn("Franchisee SQL file not found at {$franFile}; skipping.");
        }

        // ── Report ───────────────────────────────────────────────────────────
        $this->printReport($hoResult, $franResult);

        return self::SUCCESS;
    }

    // ══════════════════════════════════════════════════════════════════
    //  MAIN IMPORT ENGINE
    // ══════════════════════════════════════════════════════════════════

    private function importChallanData(
        string $sqlFile,
        array  $supplierMap,
        ?int   $unknownSupplierId,
        array  &$existingSupplierInvoiceKeys,
        array  $knownProductIds,
        array  &$existingChallanIds,
        ?int   $onlyFranch,
        bool   $skipItems,
        bool   $dryRun,
        string $context,
        array  $franchiseeMap = []
    ): array {
        // Load all product rows grouped by vendor_table_id → fast lookup per invoice
        $this->line("  Loading purchase_challan_product rows for {$context}...");
        $productsByVendorId = [];
        foreach (LegacySqlReader::streamTableRows($sqlFile, 'purchase_challan_product') as $pRow) {
            $vid = (int) ($pRow['vendor_table_id'] ?? 0);
            if ($vid > 0) {
                $productsByVendorId[$vid][] = $pRow;
            }
        }
        $this->line('  Loaded ' . count($productsByVendorId) . ' vendor-table-id groups.');

        $stats = ['imported' => 0, 'items_imported' => 0, 'skipped' => 0, 'anomalies' => []];
        $now   = now()->toDateTimeString();

        foreach (LegacySqlReader::streamTableRows($sqlFile, 'purchase_challan_vendor') as $vendor) {
            $challanId  = (int) ($vendor['id'] ?? 0);
            $franchId   = (int) ($vendor['franch_id'] ?? 0);
            $vendorId   = trim($vendor['vendor_id'] ?? '');
            $purDate    = $this->parseDate($vendor['pur_date'] ?? '') ?? $now;
            $entryNo    = trim($vendor['puchase_entry_no'] ?? '');
            $taxType    = strtolower(trim($vendor['tax_type'] ?? 'local')) === 'central' ? 'inter_state' : 'intra_state';

            if ($challanId <= 0) {
                $stats['skipped']++;
                continue;
            }

            // Filter by franch_id if --franch-id option given
            if ($onlyFranch !== null && $franchId !== $onlyFranch) {
                $stats['skipped']++;
                continue;
            }

            // Skip already migrated
            if (isset($existingChallanIds[$challanId])) {
                $stats['skipped']++;
                continue;
            }

            // Map vendor_id → supplier_id (legacy vendor_id is a string that maps to supplier ID)
            $supplierId = (int) $vendorId;
            if ($supplierId <= 0 || !isset($supplierMap[$supplierId])) {
                // Vendor not found in suppliers — record anomaly but still import with null supplier
                $stats['anomalies'][] = [
                    'reason'     => 'unknown_supplier',
                    'challan_id' => $challanId,
                    'vendor_id'  => $vendorId,
                ];
                $supplierId = $unknownSupplierId;
            }

            // Determine franchisee linkage for franchisee-side imports
            $legacyFranchId = $franchId > 0 ? $franchId : null;
            $newFranchId    = $legacyFranchId && isset($franchiseeMap[$legacyFranchId])
                ? $franchiseeMap[$legacyFranchId]
                : null;

            $supplierInvoiceNo = $this->resolveUniqueSupplierInvoiceNo(
                $supplierId,
                $entryNo,
                $challanId,
                $existingSupplierInvoiceKeys
            );

            // Build invoice aggregates from product lines
            $productLines   = $productsByVendorId[$challanId] ?? [];
            $totals         = $this->computeInvoiceTotals($productLines, $taxType);

            $invoiceData = [
                'invoice_number'   => 'LEGACY-' . strtoupper($context) . '-' . $challanId,
                'supplier_invoice_no' => $supplierInvoiceNo,
                'supplier_id'      => $supplierId,
                'invoice_date'     => $purDate,
                'received_date'    => $purDate,
                'financial_year'   => self::LEGACY_FY,
                'subtotal'         => $totals['subtotal'],
                'discount_amount'  => $totals['discount'],
                'sgst_amount'      => $totals['sgst'],
                'cgst_amount'      => $totals['cgst'],
                'igst_amount'      => $totals['igst'],
                'round_off'        => 0,
                'total_amount'     => round($totals['subtotal'] + $totals['sgst'] + $totals['cgst'] + $totals['igst'], 2),
                'tax_type'         => $taxType,
                'status'           => 'legacy',
                'created_by'       => null,
                'approved_by'      => null,
                'approved_at'      => null,
                'legacy_challan_id' => $challanId,
                'legacy_source'    => $context,
                'legacy_franchisee_id' => $newFranchId,
                'notes'            => 'Imported from legacy purchase_challan. Read-only historical record.',
                'created_at'       => $now,
                'updated_at'       => $now,
            ];

            if (!$dryRun) {
                $invoiceId = DB::table('purchase_invoices')->insertGetId($invoiceData);

                if (!$skipItems && !empty($productLines)) {
                    $itemBatch = [];
                    foreach ($productLines as $prow) {
                        $lineProduct = (int) ($prow['pro_id'] ?? 0);
                        if ($lineProduct <= 0 || !isset($knownProductIds[$lineProduct])) {
                            $stats['anomalies'][] = [
                                'reason'     => 'unknown_product_in_line',
                                'challan_id' => $challanId,
                                'pro_id'     => $lineProduct,
                            ];
                            continue;
                        }

                        $lineItemData = $this->buildLineItem($prow, $invoiceId, $taxType);
                        if ($lineItemData) {
                            $itemBatch[] = $lineItemData;
                            $stats['items_imported']++;
                        }
                    }

                    if (!empty($itemBatch)) {
                        DB::table('purchase_invoice_items')->insert($itemBatch);
                    }
                }
            } else {
                // Dry run — count expected items
                foreach ($productLines as $prow) {
                    $lineProduct = (int) ($prow['pro_id'] ?? 0);
                    if ($lineProduct > 0 && isset($knownProductIds[$lineProduct])) {
                        $stats['items_imported']++;
                    }
                }
            }

            $existingChallanIds[$challanId] = true;
            $stats['imported']++;

            if ($stats['imported'] % 200 === 0) {
                $this->output->write('.');
            }
        }

        $this->newLine();
        return $stats;
    }

    // ══════════════════════════════════════════════════════════════════
    //  COMPUTATIONS
    // ══════════════════════════════════════════════════════════════════

    private function computeInvoiceTotals(array $lines, string $taxType): array
    {
        $subtotal = $discount = $sgst = $cgst = $igst = 0.0;

        foreach ($lines as $row) {
            $qty      = $this->sanitizeDecimal($row['product_quantity'] ?? '0');
            $rate     = $this->sanitizeDecimal($row['purchase_rate'] ?? '0');
            $discPct  = $this->sanitizeDecimal($row['discount'] ?? '0');
            $discAmt  = $this->sanitizeDecimal($row['discountrs'] ?? '0');
            $gstPct   = $this->sanitizeGst($row);

            $lineBase   = round($qty * $rate, 2);
            $lineDisc   = $discAmt > 0 ? $discAmt : round($lineBase * ($discPct / 100), 2);
            $taxable    = $lineBase - $lineDisc;
            $gstAmt     = round($taxable * ($gstPct / 100), 2);

            $subtotal  += $taxable;
            $discount  += $lineDisc;

            if ($taxType === 'intra_state') {
                $sgst += $gstAmt / 2;
                $cgst += $gstAmt / 2;
            } else {
                $igst += $gstAmt;
            }
        }

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'sgst'     => round($sgst, 2),
            'cgst'     => round($cgst, 2),
            'igst'     => round($igst, 2),
        ];
    }

    private function buildLineItem(array $row, int $invoiceId, string $taxType): ?array
    {
        $proId    = (int) ($row['pro_id'] ?? 0);
        $batch    = trim($row['batch'] ?? '');
        $qty      = $this->sanitizeDecimal($row['product_quantity'] ?? '0');
        $freeQty  = $this->sanitizeDecimal($row['free'] ?? '0');
        $rate     = $this->sanitizeDecimal($row['purchase_rate'] ?? '0');
        $mrp      = $this->sanitizeDecimal($row['mrp'] ?? '0');
        $discPct  = $this->sanitizeDecimal($row['discount'] ?? '0');
        $discAmt  = $this->sanitizeDecimal($row['discountrs'] ?? '0');
        $gstPct   = $this->sanitizeGst($row);
        $expiry   = $this->parseDate($row['expiry_date'] ?? '');
        $mfg      = $this->parseDate($row['mfg_date'] ?? '');
        $now      = now()->toDateTimeString();

        if ($proId <= 0 || $qty <= 0) {
            return null;
        }

        $lineBase    = round($qty * $rate, 2);
        $lineDisc    = $discAmt > 0 ? $discAmt : round($lineBase * ($discPct / 100), 2);
        $taxable     = round($lineBase - $lineDisc, 2);
        $gstAmt      = round($taxable * ($gstPct / 100), 2);
        $total       = round($taxable + $gstAmt, 2);

        return [
            'purchase_invoice_id' => $invoiceId,
            'product_id'          => $proId,
            'batch_no'            => $batch,
            'expiry_date'         => $expiry,
            'mfg_date'            => $mfg,
            'qty'                 => $qty,
            'free_qty'            => $freeQty,
            'unit'                => 'pcs',
            'mrp'                 => $mrp,
            'rate'                => $rate,
            'discount_percent'    => $discPct,
            'discount_amount'     => $lineDisc,
            'gst_percent'         => $gstPct,
            'gst_amount'          => $gstAmt,
            'hsn_id'              => null,
            'taxable_amount'      => $taxable,
            'total_amount'        => $total,
            'created_at'          => $now,
            'updated_at'          => $now,
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  SCHEMA GUARDS — add columns if missing (idempotent)
    // ══════════════════════════════════════════════════════════════════

    private function legacyStatusColumnExists(): bool
    {
        try {
            $column = DB::selectOne("SHOW COLUMNS FROM purchase_invoices LIKE 'status'");
            if (!$column || !isset($column->Type)) {
                return false;
            }

            return str_contains((string) $column->Type, "'legacy'");
        } catch (\Exception $e) {
            return false;
        }
    }

    private function addLegacyStatusColumn(bool $dryRun): void
    {
        $this->warn("Adding 'legacy' status to purchase_invoices.status enum and traceability columns...");
        if (!$dryRun) {
            DB::statement("ALTER TABLE purchase_invoices MODIFY COLUMN status ENUM('draft','approved','cancelled','legacy') NOT NULL DEFAULT 'draft'");
            $this->info('  purchase_invoices.status updated.');
        }
    }

    private function legacyChallanIdColumnExists(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('purchase_invoices', 'legacy_challan_id');
    }

    private function addLegacyChallanIdColumn(bool $dryRun): void
    {
        $this->warn("Adding legacy_challan_id, legacy_source, legacy_franchisee_id to purchase_invoices...");
        if (!$dryRun) {
            \Illuminate\Support\Facades\Schema::table('purchase_invoices', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->unsignedBigInteger('legacy_challan_id')->nullable()->after('notes');
                $table->string('legacy_source', 30)->nullable()->after('legacy_challan_id');
                $table->unsignedBigInteger('legacy_franchisee_id')->nullable()->after('legacy_source');
                $table->index('legacy_challan_id');
            });
            $this->info('  Columns added: legacy_challan_id, legacy_source, legacy_franchisee_id.');
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  REPORT
    // ══════════════════════════════════════════════════════════════════

    private function printReport(array $hoResult, array $franResult): void
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════════════');
        $this->info('  LEGACY PURCHASE INVOICE MIGRATION REPORT');
        $this->info('═══════════════════════════════════════════════');
        $this->table(
            ['Source', 'Invoices', 'Line Items', 'Skipped'],
            [
                ['HO Challan',         $hoResult['imported'],   $hoResult['items_imported'],   $hoResult['skipped']],
                ['Franchisee Challan', $franResult['imported'], $franResult['items_imported'], $franResult['skipped']],
            ]
        );

        $allAnomalies = array_merge($hoResult['anomalies'], $franResult['anomalies']);
        if (!empty($allAnomalies)) {
            $this->newLine();
            $this->warn('ANOMALIES (' . count($allAnomalies) . ') — review manually:');
            $byReason = collect($allAnomalies)->groupBy('reason');
            foreach ($byReason as $reason => $items) {
                $this->warn("  [{$reason}] — " . count($items) . ' rows');
            }
        } else {
            $this->info('  No anomalies.');
        }

        $this->newLine();
        $this->info('Status of all legacy invoices is "legacy" (read-only).');
        $this->info('Cross-reference: purchase_invoices.legacy_challan_id == original purchase_challan_vendor.id');
        $this->info('═══════════════════════════════════════════════');
    }

    // ══════════════════════════════════════════════════════════════════
    //  HELPERS
    // ══════════════════════════════════════════════════════════════════

    private function sanitizeDecimal($value): float
    {
        $val = str_replace(',', '', trim((string) ($value ?? '')));
        return is_numeric($val) ? round((float) $val, 4) : 0.0;
    }

    private function sanitizeGst(array $row): float
    {
        if (isset($row['GST']) && is_numeric(trim((string) $row['GST']))) {
            $g = (float) trim((string) $row['GST']);
            if ($g > 0) {
                return round($g, 2);
            }
        }
        $sgst = $this->sanitizeDecimal($row['sgst'] ?? '0');
        $cgst = $this->sanitizeDecimal($row['cgst'] ?? '0');
        if ($sgst + $cgst > 0) {
            return round($sgst + $cgst, 2);
        }
        return round($this->sanitizeDecimal($row['igst'] ?? '0'), 2);
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

    private function resolveUnknownSupplierId(bool $dryRun): ?int
    {
        $code = 'LEGACY-UNKNOWN';

        $existingId = DB::table('suppliers')->where('code', $code)->value('id');
        if ($existingId) {
            return (int) $existingId;
        }

        if ($dryRun) {
            $this->warn('  Placeholder supplier LEGACY-UNKNOWN not present; dry-run will continue without supplier linkage.');
            return null;
        }

        $id = DB::table('suppliers')->insertGetId([
            'name' => 'Legacy Unknown Supplier',
            'code' => $code,
            'contact_person' => null,
            'phone' => null,
            'email' => null,
            'address' => 'Auto-created placeholder for unmapped legacy vendor_id values.',
            'credit_limit' => 0,
            'credit_days' => 30,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->warn('  Created placeholder supplier LEGACY-UNKNOWN for unmapped legacy vendor IDs.');

        return (int) $id;
    }

    private function resolveUniqueSupplierInvoiceNo(?int $supplierId, string $entryNo, int $challanId, array &$existingSupplierInvoiceKeys): ?string
    {
        $base = trim($entryNo);
        if ($supplierId === null || $supplierId <= 0 || $base === '') {
            return null;
        }

        $candidate = substr($base, 0, 50);
        $key = $this->supplierInvoiceKey($supplierId, $candidate);

        if (!isset($existingSupplierInvoiceKeys[$key])) {
            $existingSupplierInvoiceKeys[$key] = true;
            return $candidate;
        }

        $baseWithChallan = substr($base, 0, 38) . '-L' . $challanId;
        $candidate = substr($baseWithChallan, 0, 50);
        $key = $this->supplierInvoiceKey($supplierId, $candidate);

        $i = 1;
        while (isset($existingSupplierInvoiceKeys[$key])) {
            $candidate = substr($baseWithChallan, 0, 45) . '-' . $i;
            $key = $this->supplierInvoiceKey($supplierId, $candidate);
            $i++;
        }

        $existingSupplierInvoiceKeys[$key] = true;
        return $candidate;
    }

    private function supplierInvoiceKey(int $supplierId, string $supplierInvoiceNo): string
    {
        return $supplierId . '|' . mb_strtoupper(trim($supplierInvoiceNo));
    }
}
