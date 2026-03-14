<?php

namespace App\Console\Commands;

use App\Helpers\LegacySqlReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ReconcileLegacyPurchaseData extends Command
{
    protected $signature = 'erp:reconcile-legacy-purchase-data
                            {--ho-file= : Absolute path to pharmaer_pharmaerp.sql}
                            {--fran-file= : Absolute path to genericp_franchisee.sql}
                            {--dry-run : Preview only; do not update DB}
                            {--write-report : Write JSON report to storage/app/reports}';

    protected $description = 'Reconcile legacy purchase anomalies: supplier remap, franchise product-id mapping recovery, and unresolved report output.';

    public function handle(): int
    {
        $hoFile = $this->option('ho-file') ?: base_path('../pharmaer_pharmaerp.sql');
        $franFile = $this->option('fran-file') ?: base_path('../genericp_franchisee.sql');
        $dryRun = (bool) $this->option('dry-run');
        $writeReport = (bool) $this->option('write-report');

        if (!file_exists($hoFile)) {
            $this->error("HO SQL file not found: {$hoFile}");
            return self::FAILURE;
        }

        if (!file_exists($franFile)) {
            $this->error("Franchise SQL file not found: {$franFile}");
            return self::FAILURE;
        }

        $this->info('=== Legacy Purchase Reconciliation' . ($dryRun ? ' [DRY RUN]' : '') . ' ===');

        $knownProductIds = DB::table('products')->pluck('id')->flip()->toArray();
        $skuLegacyProductMap = $this->buildSkuLegacyProductMap();
        $this->line('Known products: ' . count($knownProductIds));
        $this->line('SKU legacy map rows: ' . count($skuLegacyProductMap));

        $placeholderSupplierId = DB::table('suppliers')->where('code', 'LEGACY-UNKNOWN')->value('id');
        $this->line('Placeholder supplier: ' . ($placeholderSupplierId ?: 'NOT FOUND'));

        $this->info('Loading legacy vendor maps...');
        $vendorMapHo = $this->buildVendorMap($hoFile);
        $vendorMapFran = $this->buildVendorMap($franFile);
        $vendorNameMapHo = $this->buildVendorNameMap($hoFile);
        $vendorNameMapFran = $this->buildVendorNameMap($franFile);
        $supplierLookup = $this->buildSupplierLookup();

        $this->info('Loading franchise product-id mapping...');
        $franchiseProductMap = $this->buildFranchiseProductMap($franFile);
        $this->line('Franchise product map rows: ' . count($franchiseProductMap));

        $report = [
            'generated_at' => now()->toIso8601String(),
            'dry_run' => $dryRun,
            'supplier_reconcile' => [
                'updated' => 0,
                'created_suppliers' => 0,
                'unmapped' => 0,
                'samples' => [],
            ],
            'line_recovery' => [
                'inserted' => 0,
                'invoices_touched' => 0,
                'unresolved_product_lines' => 0,
                'samples' => [],
            ],
        ];

        $report['supplier_reconcile'] = $this->reconcileSuppliers(
            $placeholderSupplierId,
            $vendorMapHo,
            $vendorMapFran,
            $vendorNameMapHo,
            $vendorNameMapFran,
            $supplierLookup,
            $dryRun
        );

        $this->info('Building expected purchase lines from legacy dumps...');
        $unresolvedSamples = [];
        $expectedHo = $this->buildExpectedLineMap($hoFile, 'ho', [], $knownProductIds, $skuLegacyProductMap, $report['line_recovery'], $unresolvedSamples);
        $expectedFran = $this->buildExpectedLineMap($franFile, 'franchisee', $franchiseProductMap, $knownProductIds, $skuLegacyProductMap, $report['line_recovery'], $unresolvedSamples);
        $expectedBySource = [
            'ho' => $expectedHo,
            'franchisee' => $expectedFran,
        ];

        $recovery = $this->recoverMissingItems($expectedBySource, $dryRun);
        $report['line_recovery']['inserted'] = $recovery['inserted'];
        $report['line_recovery']['invoices_touched'] = $recovery['invoices_touched'];
        $report['line_recovery']['samples'] = array_slice($unresolvedSamples, 0, 200);

        if ($writeReport) {
            $this->writeReport($report);
        }

        $this->newLine();
        $this->info('=== RECONCILIATION SUMMARY ===');
        $this->table(
            ['Track', 'Updated/Inserted', 'Unresolved'],
            [
                ['Supplier remap', $report['supplier_reconcile']['updated'] . ' (created ' . $report['supplier_reconcile']['created_suppliers'] . ')', $report['supplier_reconcile']['unmapped']],
                ['Missing line recovery', $report['line_recovery']['inserted'], $report['line_recovery']['unresolved_product_lines']],
            ]
        );

        return self::SUCCESS;
    }

    private function buildVendorMap(string $sqlFile): array
    {
        $map = [];
        foreach (LegacySqlReader::streamTableRows($sqlFile, 'purchase_challan_vendor') as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $map[$id] = trim((string) ($row['vendor_id'] ?? ''));
        }

        return $map;
    }

    private function buildVendorNameMap(string $sqlFile): array
    {
        $map = [];

        foreach (LegacySqlReader::streamTableRows($sqlFile, 'create_new_ledger') as $row) {
            $id = (int) ($row['led_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $name = trim((string) ($row['ledger_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $map[$id] = $name;
        }

        return $map;
    }

    private function buildSupplierLookup(): array
    {
        $lookup = [
            'ids' => [],
            'codes' => [],
            'normalized_codes' => [],
            'normalized_names' => [],
        ];

        DB::table('suppliers')
            ->select('id', 'name', 'code')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$lookup) {
                foreach ($rows as $row) {
                    $id = (int) $row->id;
                    $lookup['ids'][$id] = true;

                    $code = trim((string) ($row->code ?? ''));
                    if ($code !== '') {
                        $upperCode = strtoupper($code);
                        $lookup['codes'][$upperCode] = $id;

                        $normalizedCode = $this->normalizeKey($code);
                        if ($normalizedCode !== '' && !isset($lookup['normalized_codes'][$normalizedCode])) {
                            $lookup['normalized_codes'][$normalizedCode] = $id;
                        }
                    }

                    $name = trim((string) ($row->name ?? ''));
                    $normalizedName = $this->normalizeKey($name);
                    if ($normalizedName !== '' && !isset($lookup['normalized_names'][$normalizedName])) {
                        $lookup['normalized_names'][$normalizedName] = $id;
                    }
                }
            });

        return $lookup;
    }

    private function buildSkuLegacyProductMap(): array
    {
        $map = [];

        DB::table('products')
            ->select('id', 'sku')
            ->whereNotNull('sku')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$map) {
                foreach ($rows as $row) {
                    $sku = strtoupper(trim((string) ($row->sku ?? '')));
                    if (preg_match('/^PRD-(\d+)$/', $sku, $m)) {
                        $legacyId = (int) $m[1];
                        if ($legacyId > 0 && !isset($map[$legacyId])) {
                            $map[$legacyId] = (int) $row->id;
                        }
                    }
                }
            });

        return $map;
    }

    private function buildFranchiseProductMap(string $sqlFile): array
    {
        $map = [];
        $productCodeToPharmaId = [];

        foreach (LegacySqlReader::streamTableRows($sqlFile, 'add_new_product') as $row) {
            $localId = (int) ($row['pro_id'] ?? 0);
            $pharmaId = (int) ($row['pharma_pro_id'] ?? 0);
            if ($localId > 0 && $pharmaId > 0) {
                $map[$localId] = $pharmaId;

                $franchNewProductId = (int) ($row['franch_new_product_id'] ?? 0);
                if ($franchNewProductId > 0 && !isset($map[$franchNewProductId])) {
                    $map[$franchNewProductId] = $pharmaId;
                }

                $productCode = $this->normalizeKey((string) ($row['product_code'] ?? ''));
                if ($productCode !== '' && !isset($productCodeToPharmaId[$productCode])) {
                    $productCodeToPharmaId[$productCode] = $pharmaId;
                }
            }
        }

        foreach (LegacySqlReader::streamTableRows($sqlFile, 'franch_new_product') as $row) {
            $localId = (int) ($row['franch_pro_id'] ?? 0);
            $pharmaId = (int) ($row['pharma_pro_id'] ?? 0);
            if ($localId > 0 && $pharmaId > 0 && !isset($map[$localId])) {
                $map[$localId] = $pharmaId;
                continue;
            }

            if ($localId > 0 && !isset($map[$localId])) {
                $productCode = $this->normalizeKey((string) ($row['product_code'] ?? ''));
                if ($productCode !== '' && isset($productCodeToPharmaId[$productCode])) {
                    $map[$localId] = (int) $productCodeToPharmaId[$productCode];
                }
            }
        }

        return $map;
    }

    private function reconcileSuppliers(
        ?int $placeholderSupplierId,
        array $vendorMapHo,
        array $vendorMapFran,
        array $vendorNameMapHo,
        array $vendorNameMapFran,
        array $supplierLookup,
        bool $dryRun
    ): array
    {
        $stats = ['updated' => 0, 'created_suppliers' => 0, 'unmapped' => 0, 'samples' => []];
        $createdSupplierByVendorId = [];

        if (!$placeholderSupplierId) {
            $this->warn('Placeholder supplier LEGACY-UNKNOWN missing; supplier remap skipped.');
            return $stats;
        }

        $this->info('Reconciling legacy unknown suppliers...');

        DB::table('purchase_invoices')
            ->where('status', 'legacy')
            ->where('supplier_id', $placeholderSupplierId)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$stats, $vendorMapHo, $vendorMapFran, $vendorNameMapHo, $vendorNameMapFran, &$supplierLookup, &$createdSupplierByVendorId, $dryRun) {
                foreach ($rows as $invoice) {
                    $source = (string) ($invoice->legacy_source ?? 'ho');
                    $challanId = (int) ($invoice->legacy_challan_id ?? 0);
                    if ($challanId <= 0) {
                        continue;
                    }

                    $vendorMap = $source === 'franchisee' ? $vendorMapFran : $vendorMapHo;
                    $vendorNameMap = $source === 'franchisee' ? $vendorNameMapFran : $vendorNameMapHo;
                    $legacyVendorId = trim((string) ($vendorMap[$challanId] ?? ''));
                    $legacyVendorName = trim((string) ($vendorNameMap[(int) $legacyVendorId] ?? ''));

                    $targetSupplierId = $this->resolveSupplierId($legacyVendorId, $legacyVendorName, $supplierLookup);

                    if (!$targetSupplierId) {
                        $targetSupplierId = $this->createSupplierFromLegacyVendor(
                            $legacyVendorId,
                            $legacyVendorName,
                            $supplierLookup,
                            $createdSupplierByVendorId,
                            $dryRun
                        );

                        if ($targetSupplierId) {
                            $stats['created_suppliers']++;
                        }
                    }

                    if (!$targetSupplierId) {
                        $stats['unmapped']++;
                        if (count($stats['samples']) < 200) {
                            $stats['samples'][] = [
                                'invoice_id' => (int) $invoice->id,
                                'source' => $source,
                                'legacy_challan_id' => $challanId,
                                'legacy_vendor_id' => $legacyVendorId,
                                'legacy_vendor_name' => $legacyVendorName,
                            ];
                        }
                        continue;
                    }

                    if (!$dryRun) {
                        DB::table('purchase_invoices')
                            ->where('id', $invoice->id)
                            ->update([
                                'supplier_id' => $targetSupplierId,
                                'updated_at' => now(),
                            ]);
                    }

                    $stats['updated']++;
                }
            });

        return $stats;
    }

    private function createSupplierFromLegacyVendor(
        string $legacyVendorId,
        string $legacyVendorName,
        array &$supplierLookup,
        array &$createdSupplierByVendorId,
        bool $dryRun
    ): ?int {
        if (!is_numeric($legacyVendorId)) {
            return null;
        }

        $legacyId = (int) $legacyVendorId;
        if ($legacyId <= 0) {
            return null;
        }

        if (isset($createdSupplierByVendorId[$legacyId])) {
            return (int) $createdSupplierByVendorId[$legacyId];
        }

        $name = trim($legacyVendorName);
        if ($name === '') {
            return null;
        }

        $baseCode = 'V-' . $legacyId;
        $code = $baseCode;
        $suffix = 1;

        while (isset($supplierLookup['codes'][strtoupper($code)])) {
            $code = 'V-' . $legacyId . '-' . $suffix;
            $suffix++;
            if ($suffix > 99) {
                return null;
            }
        }

        if ($dryRun) {
            return null;
        }

        $supplierId = (int) DB::table('suppliers')->insertGetId([
            'name' => $name,
            'code' => substr($code, 0, 20),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $createdSupplierByVendorId[$legacyId] = $supplierId;
        $supplierLookup['ids'][$supplierId] = true;
        $supplierLookup['codes'][strtoupper($code)] = $supplierId;

        $normalizedCode = $this->normalizeKey($code);
        if ($normalizedCode !== '' && !isset($supplierLookup['normalized_codes'][$normalizedCode])) {
            $supplierLookup['normalized_codes'][$normalizedCode] = $supplierId;
        }

        $normalizedName = $this->normalizeKey($name);
        if ($normalizedName !== '' && !isset($supplierLookup['normalized_names'][$normalizedName])) {
            $supplierLookup['normalized_names'][$normalizedName] = $supplierId;
        }

        return $supplierId;
    }

    private function resolveSupplierId(string $legacyVendorId, string $legacyVendorName, array $supplierLookup): ?int
    {
        if ($legacyVendorId === '') {
            return $this->resolveSupplierByName($legacyVendorName, $supplierLookup);
        }

        if (is_numeric($legacyVendorId)) {
            $id = (int) $legacyVendorId;
            if (isset($supplierLookup['ids'][$id])) {
                return $id;
            }

            $prefixedCode = 'V-' . $id;
            if (isset($supplierLookup['codes'][$prefixedCode])) {
                return (int) $supplierLookup['codes'][$prefixedCode];
            }

            $prefixedNormalized = $this->normalizeKey($prefixedCode);
            if ($prefixedNormalized !== '' && isset($supplierLookup['normalized_codes'][$prefixedNormalized])) {
                return (int) $supplierLookup['normalized_codes'][$prefixedNormalized];
            }
        }

        $upperCode = strtoupper($legacyVendorId);
        if (isset($supplierLookup['codes'][$upperCode])) {
            return (int) $supplierLookup['codes'][$upperCode];
        }

        $normalizedCode = $this->normalizeKey($legacyVendorId);
        if ($normalizedCode !== '' && isset($supplierLookup['normalized_codes'][$normalizedCode])) {
            return (int) $supplierLookup['normalized_codes'][$normalizedCode];
        }

        return $this->resolveSupplierByName($legacyVendorName, $supplierLookup);
    }

    private function resolveSupplierByName(string $legacyVendorName, array $supplierLookup): ?int
    {
        $normalizedName = $this->normalizeKey($legacyVendorName);
        if ($normalizedName !== '' && isset($supplierLookup['normalized_names'][$normalizedName])) {
            return (int) $supplierLookup['normalized_names'][$normalizedName];
        }

        return null;
    }

    private function buildExpectedLineMap(
        string $sqlFile,
        string $source,
        array $franchiseProductMap,
        array $knownProductIds,
        array $skuLegacyProductMap,
        array &$lineRecovery,
        array &$unresolvedSamples
    ): array {
        $map = [];

        foreach (LegacySqlReader::streamTableRows($sqlFile, 'purchase_challan_product') as $row) {
            $challanId = (int) ($row['vendor_table_id'] ?? 0);
            if ($challanId <= 0) {
                continue;
            }

            $proId = (int) ($row['pro_id'] ?? 0);
            $resolvedProductId = $this->resolveProductId($proId, $source, $franchiseProductMap, $knownProductIds, $skuLegacyProductMap);

            if (!$resolvedProductId) {
                $lineRecovery['unresolved_product_lines']++;
                if (count($unresolvedSamples) < 200) {
                    $unresolvedSamples[] = [
                        'source' => $source,
                        'legacy_challan_id' => $challanId,
                        'legacy_pro_id' => $proId,
                        'batch' => trim((string) ($row['batch'] ?? '')),
                    ];
                }
                continue;
            }

            $qty = $this->sanitizeDecimal($row['product_quantity'] ?? '0');
            if ($qty <= 0) {
                continue;
            }

            $map[$challanId][] = [
                'product_id' => $resolvedProductId,
                'batch' => trim((string) ($row['batch'] ?? '')),
                'qty' => $qty,
                'free' => $this->sanitizeDecimal($row['free'] ?? '0'),
                'rate' => $this->sanitizeDecimal($row['purchase_rate'] ?? '0'),
                'mrp' => $this->sanitizeDecimal($row['mrp'] ?? '0'),
                'discount' => $this->sanitizeDecimal($row['discount'] ?? '0'),
                'discountrs' => $this->sanitizeDecimal($row['discountrs'] ?? '0'),
                'gst' => $this->sanitizeGst($row),
                'expiry_date' => $this->parseDate($row['expiry_date'] ?? ''),
                'mfg_date' => $this->parseDate($row['mfg_date'] ?? ''),
            ];
        }

        return $map;
    }

    private function resolveProductId(
        int $legacyProductId,
        string $source,
        array $franchiseProductMap,
        array $knownProductIds,
        array $skuLegacyProductMap
    ): ?int
    {
        if ($legacyProductId > 0 && isset($knownProductIds[$legacyProductId])) {
            return $legacyProductId;
        }

        if ($legacyProductId > 0 && isset($skuLegacyProductMap[$legacyProductId])) {
            return (int) $skuLegacyProductMap[$legacyProductId];
        }

        if ($source === 'franchisee' && $legacyProductId > 0 && isset($franchiseProductMap[$legacyProductId])) {
            $mapped = (int) $franchiseProductMap[$legacyProductId];
            if (isset($knownProductIds[$mapped])) {
                return $mapped;
            }

            if (isset($skuLegacyProductMap[$mapped])) {
                return (int) $skuLegacyProductMap[$mapped];
            }
        }

        return null;
    }

    private function normalizeKey(string $value): string
    {
        $upper = strtoupper(trim($value));
        if ($upper === '') {
            return '';
        }

        $normalized = preg_replace('/[^A-Z0-9]+/', '', $upper);
        return is_string($normalized) ? $normalized : '';
    }

    private function recoverMissingItems(array $expectedBySource, bool $dryRun): array
    {
        $stats = ['inserted' => 0, 'invoices_touched' => 0];

        $this->info('Recovering missing legacy invoice line items...');

        DB::table('purchase_invoices')
            ->where('status', 'legacy')
            ->orderBy('id')
            ->chunkById(200, function ($invoices) use (&$stats, $expectedBySource, $dryRun) {
                foreach ($invoices as $invoice) {
                    $source = (string) ($invoice->legacy_source ?? 'ho');
                    $challanId = (int) ($invoice->legacy_challan_id ?? 0);
                    if ($challanId <= 0) {
                        continue;
                    }

                    $expectedLines = $expectedBySource[$source][$challanId] ?? [];
                    if ($expectedLines === []) {
                        continue;
                    }

                    $existing = DB::table('purchase_invoice_items')
                        ->where('purchase_invoice_id', $invoice->id)
                        ->select('product_id', 'batch_no', 'qty', 'rate')
                        ->get();

                    $existingSignatures = [];
                    foreach ($existing as $e) {
                        $existingSignatures[$this->lineSignature((int) $e->product_id, (string) $e->batch_no, (float) $e->qty, (float) $e->rate)] = true;
                    }

                    $insertRows = [];
                    foreach ($expectedLines as $line) {
                        $sig = $this->lineSignature((int) $line['product_id'], (string) $line['batch'], (float) $line['qty'], (float) $line['rate']);
                        if (isset($existingSignatures[$sig])) {
                            continue;
                        }

                        $insertRows[] = $this->buildLineItemRow($invoice->id, $invoice->tax_type, $line);
                        $existingSignatures[$sig] = true;
                    }

                    if ($insertRows === []) {
                        continue;
                    }

                    if (!$dryRun) {
                        DB::table('purchase_invoice_items')->insert($insertRows);
                        $this->recomputeInvoiceTotals((int) $invoice->id, (string) $invoice->tax_type);
                    }

                    $stats['inserted'] += count($insertRows);
                    $stats['invoices_touched']++;
                }
            });

        return $stats;
    }

    private function lineSignature(int $productId, string $batchNo, float $qty, float $rate): string
    {
        return implode('|', [$productId, trim($batchNo), number_format($qty, 4, '.', ''), number_format($rate, 4, '.', '')]);
    }

    private function buildLineItemRow(int $invoiceId, string $taxType, array $line): array
    {
        $lineBase = round($line['qty'] * $line['rate'], 2);
        $lineDisc = $line['discountrs'] > 0 ? $line['discountrs'] : round($lineBase * ($line['discount'] / 100), 2);
        $taxable = round($lineBase - $lineDisc, 2);
        $gstAmt = round($taxable * ($line['gst'] / 100), 2);
        $total = round($taxable + $gstAmt, 2);

        return [
            'purchase_invoice_id' => $invoiceId,
            'product_id' => (int) $line['product_id'],
            'batch_no' => $line['batch'],
            'expiry_date' => $line['expiry_date'],
            'mfg_date' => $line['mfg_date'],
            'qty' => $line['qty'],
            'free_qty' => $line['free'],
            'unit' => 'pcs',
            'mrp' => $line['mrp'],
            'rate' => $line['rate'],
            'discount_percent' => $line['discount'],
            'discount_amount' => $lineDisc,
            'gst_percent' => $line['gst'],
            'gst_amount' => $gstAmt,
            'hsn_id' => null,
            'taxable_amount' => $taxable,
            'total_amount' => $total,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function recomputeInvoiceTotals(int $invoiceId, string $taxType): void
    {
        $rows = DB::table('purchase_invoice_items')
            ->where('purchase_invoice_id', $invoiceId)
            ->select('taxable_amount', 'discount_amount', 'gst_amount')
            ->get();

        $subtotal = round((float) $rows->sum('taxable_amount'), 2);
        $discount = round((float) $rows->sum('discount_amount'), 2);
        $gstTotal = round((float) $rows->sum('gst_amount'), 2);

        $sgst = 0.0;
        $cgst = 0.0;
        $igst = 0.0;

        if ($taxType === 'intra_state') {
            $sgst = round($gstTotal / 2, 2);
            $cgst = round($gstTotal / 2, 2);
        } else {
            $igst = $gstTotal;
        }

        DB::table('purchase_invoices')
            ->where('id', $invoiceId)
            ->update([
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'sgst_amount' => $sgst,
                'cgst_amount' => $cgst,
                'igst_amount' => $igst,
                'total_amount' => round($subtotal + $sgst + $cgst + $igst, 2),
                'updated_at' => now(),
            ]);
    }

    private function writeReport(array $report): void
    {
        $dir = storage_path('app/reports');
        File::ensureDirectoryExists($dir);

        $path = $dir . '/legacy_purchase_reconcile_' . now()->format('Ymd_His') . '.json';
        File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info('Wrote report: ' . $path);
    }

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
        $v = trim((string) ($value ?? ''));
        if ($v === '' || $v === '0000-00-00') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($v)->toDateString();
        } catch (\Exception $e) {
            return null;
        }
    }
}
