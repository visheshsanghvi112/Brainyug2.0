<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\LegacySqlReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateLegacyProducts extends Command
{
    protected $signature = 'erp:migrate-legacy-products {--file= : Absolute path to pharmaer_pharmaerp.sql}';
    protected $description = 'Migrate legacy products, categories, box sizes from SQL dump.';

    private array $categoryMap = [];
    private array $boxSizeMap = [];
    private array $hsnIdMap = [];

    public function handle()
    {
        $filePath = $this->option('file') ?: base_path('../pharmaer_pharmaerp.sql');

        if (!file_exists($filePath)) {
            $this->error("SQL file not found at: {$filePath}");
            return Command::FAILURE;
        }

        $this->info("Starting legacy product migration...");

        DB::beginTransaction();
        try {
            $this->fillMasterGaps($filePath);
            $this->buildHsnMapping($filePath);
            $this->populateCategories($filePath);
            $this->populateBoxSizes($filePath);
            $this->migrateProducts($filePath);

            DB::commit();
            $this->info("Product migration completed successfully.");
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Migration failed: " . $e->getMessage());
            $this->error("Line: " . $e->getFile() . ':' . $e->getLine());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Create placeholder entries for FK references that don't exist in masters.
     */
    private function fillMasterGaps(string $filePath): void
    {
        $this->info("Filling master data gaps...");

        // Placeholder companies for IDs 22, 377 (don't exist in legacy either)
        $missingCompanyIds = [22, 377];
        foreach ($missingCompanyIds as $cid) {
            if (!DB::table('company_masters')->where('id', $cid)->exists()) {
                DB::table('company_masters')->insert([
                    'id' => $cid,
                    'name' => "Unknown Manufacturer (Legacy #{$cid})",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->line("  Created placeholder company #{$cid}");
            }
        }

        // Placeholder salts for IDs 343, 395, 1000 (don't exist in legacy either)
        $missingSaltIds = [343, 395, 1000];
        foreach ($missingSaltIds as $sid) {
            if (!DB::table('salt_masters')->where('id', $sid)->exists()) {
                DB::table('salt_masters')->insert([
                    'id' => $sid,
                    'name' => "Unknown Salt (Legacy #{$sid})",
                    'is_narcotic' => false,
                    'schedule_h' => false,
                    'schedule_h1' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->line("  Created placeholder salt #{$sid}");
            }
        }

        // A "catch-all" company, salt, HSN for products with empty/zero FKs
        $this->ensurePlaceholder('company_masters', 'name', 'Unassigned');
        $this->ensurePlaceholder('salt_masters', 'name', 'Unassigned', [
            'is_narcotic' => false, 'schedule_h' => false, 'schedule_h1' => false,
        ]);
        $this->ensurePlaceholder('hsn_masters', 'hsn_code', '00000000', [
            'hsn_name' => 'Not Applicable',
            'cgst_percent' => 0, 'sgst_percent' => 0, 'igst_percent' => 0,
        ]);

        // Insert the 21 missing HSN entries that exist in legacy but were skipped (duplicate codes)
        // These need to be mapped via hsn_code, not inserted with their legacy ID
        // (because the code already exists under a different ID in the new DB)
        // Handled by buildHsnMapping() instead.

        // 4 phantom HSN IDs (81, 208, 4, 116) - not in legacy either
        // Products referencing these will be mapped to the "00000000" placeholder
        $this->info("  Master gaps filled.");
    }

    /**
     * Ensure a placeholder row exists in a table. Returns the ID.
     */
    private function ensurePlaceholder(string $table, string $nameCol, string $nameVal, array $extra = []): int
    {
        $existing = DB::table($table)->where($nameCol, $nameVal)->first();
        if ($existing) {
            return $existing->id;
        }

        return DB::table($table)->insertGetId(array_merge([
            $nameCol => $nameVal,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    /**
     * Build mapping from legacy HSN IDs to new DB HSN IDs via hsn_code.
     * Most IDs match 1:1 (already migrated), but duplicates were de-duped.
     */
    private function buildHsnMapping(string $filePath): void
    {
        $this->info("Building HSN ID mapping...");

        // Load all legacy HSN entries: id → hsn_code
        $legacyHsnCodes = [];
        foreach (LegacySqlReader::streamTableRows($filePath, 'hsn_master') as $row) {
            $legacyHsnCodes[(int)$row['id']] = trim($row['hsn_code']);
        }

        // Load new DB HSN entries: hsn_code → id
        $newHsnByCode = [];
        foreach (DB::table('hsn_masters')->get(['id', 'hsn_code']) as $row) {
            $newHsnByCode[$row->hsn_code] = $row->id;
        }

        // Build legacy_id → new_id map
        foreach ($legacyHsnCodes as $legacyId => $code) {
            if (isset($newHsnByCode[$code])) {
                $this->hsnIdMap[$legacyId] = $newHsnByCode[$code];
            }
        }

        // Fallback HSN ID for unmapped/empty references
        $this->hsnIdMap[0] = DB::table('hsn_masters')->where('hsn_code', '00000000')->value('id');

        $this->info("  Mapped " . count($this->hsnIdMap) . " HSN IDs.");
    }

    /**
     * Populate item_categories from the distinct category values in legacy products.
     */
    private function populateCategories(string $filePath): void
    {
        $this->info("Populating item_categories...");

        $categoryNames = [
            'COM' => 'Commercial',
            'NCOM' => 'Non-Commercial',
            'NRX' => 'Non-RX (OTC)',
        ];

        // Always create Uncategorized as default
        $uncatId = $this->ensurePlaceholder('item_categories', 'name', 'Uncategorized');
        $this->categoryMap[''] = $uncatId;

        foreach ($categoryNames as $code => $fullName) {
            $displayName = "{$fullName} ({$code})";
            $existing = DB::table('item_categories')->where('name', $displayName)->first();
            if ($existing) {
                $this->categoryMap[$code] = $existing->id;
            } else {
                $id = DB::table('item_categories')->insertGetId([
                    'name' => $displayName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->categoryMap[$code] = $id;
                $this->line("  Created category: {$displayName} (ID={$id})");
            }
        }

        $this->info("  " . count($this->categoryMap) . " categories ready.");
    }

    /**
     * Populate box_sizes from unique box_size values in legacy products.
     */
    private function populateBoxSizes(string $filePath): void
    {
        $this->info("Populating box_sizes...");

        // Collect unique box_size values
        $sizes = [];
        foreach (LegacySqlReader::streamTableRows($filePath, 'add_new_product') as $row) {
            $bs = trim($row['box_size']);
            if ($bs !== '') {
                $sizes[$bs] = true;
            }
        }

        foreach (array_keys($sizes) as $sizeVal) {
            $sizeName = (string)$sizeVal;
            $existing = DB::table('box_sizes')->where('size_name', $sizeName)->first();
            if ($existing) {
                $this->boxSizeMap[$sizeVal] = $existing->id;
            } else {
                $id = DB::table('box_sizes')->insertGetId([
                    'size_name' => $sizeName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->boxSizeMap[$sizeVal] = $id;
            }
        }

        // For empty box_size, create a "N/A" entry
        $naId = $this->ensurePlaceholder('box_sizes', 'size_name', 'N/A');
        $this->boxSizeMap[''] = $naId;
        $this->boxSizeMap['0'] = $this->boxSizeMap['0'] ?? $naId;

        $this->info("  " . count($this->boxSizeMap) . " box sizes ready.");
    }

    private function sanitizeDecimal($value): float
    {
        if ($value === null || trim((string)$value) === '') {
            return 0.00;
        }
        $val = str_replace(',', '', trim((string)$value));
        return is_numeric($val) ? round((float)$val, 2) : 0.00;
    }

    private function sanitizeInt($value): int
    {
        if ($value === null || trim((string)$value) === '') {
            return 0;
        }
        $val = trim((string)$value);
        return is_numeric($val) ? (int)$val : 0;
    }

    /**
     * Parse shelflife string like "24 MONTHS" to integer months.
     */
    private function parseShelflife($value): ?int
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        $val = trim((string)$value);
        if (preg_match('/(\d+)/', $val, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /**
     * Migrate all legacy products.
     */
    private function migrateProducts(string $filePath): void
    {
        $this->info("Migrating products...");

        // Lookup IDs for fallback placeholders
        $fallbackCompanyId = DB::table('company_masters')->where('name', 'Unassigned')->value('id');
        $fallbackSaltId = DB::table('salt_masters')->where('name', 'Unassigned')->value('id');
        $fallbackHsnId = $this->hsnIdMap[0];
        $fallbackCategoryId = $this->categoryMap[''];
        $fallbackBoxSizeId = $this->boxSizeMap[''];

        // Track existing product IDs to avoid duplicates
        $existingIds = DB::table('products')->pluck('id')->flip()->toArray();

        $count = 0;
        $skipped = 0;

        foreach (LegacySqlReader::streamTableRows($filePath, 'add_new_product') as $row) {
            $proId = (int)$row['pro_id'];

            if (isset($existingIds[$proId])) {
                $skipped++;
                continue;
            }

            // Resolve FKs
            $companyId = $this->resolveCompanyId($row['company'], $fallbackCompanyId);
            $saltId = $this->resolveSaltId($row['salt'], $fallbackSaltId);
            $hsnId = $this->resolveHsnId($row['hsn_sac'], $fallbackHsnId);
            $categoryId = $this->resolveCategoryId($row['category']);
            $boxSizeId = $this->resolveBoxSizeId($row['box_size'], $fallbackBoxSizeId);

            // Generate SKU from pro_id (guaranteed unique), store product_code separately
            $productCode = trim($row['product_code'] ?? '');
            $sku = 'PRD-' . $proId;

            // Consolidate images into JSON
            $images = array_filter([
                'front' => trim($row['front_image'] ?? ''),
                'back' => trim($row['back_image'] ?? ''),
                'left' => trim($row['left_image'] ?? ''),
                'right' => trim($row['right_image'] ?? ''),
            ]);

            // Parse boolean fields
            $isActive = strtolower(trim($row['status'])) === 'continue';
            $hide = strtolower(trim($row['hide'])) === 'yes';
            $isLoose = trim($row['is_loose']) === '1';

            $data = [
                'id' => $proId,
                'company_id' => $companyId,
                'company_code' => trim($row['company_code'] ?? '') ?: null,
                'category_id' => $categoryId,
                'rack_section_id' => $this->sanitizeInt($row['rack_section_id']) ?: null,
                'rack_area_id' => $this->sanitizeInt($row['rack_area_id']) ?: null,
                'salt_id' => $saltId,
                'hsn_id' => $hsnId,
                'box_size_id' => $boxSizeId,
                'product_name' => trim($row['product_name']),
                'sku' => $sku,
                'product_code' => $productCode ?: null,
                'item_type' => trim($row['item_type'] ?? '') ?: null,
                'color_item_type' => trim($row['color_item_type'] ?? '') ?: null,
                'barcode' => null,
                'unit_sms_code' => trim($row['unit_sms_code'] ?? '') ?: null,
                'mrp' => $this->sanitizeDecimal($row['mrp_rate']),
                'ptr' => $this->sanitizeDecimal($row['p_rate']),
                'pts' => 0.00,
                'cost' => $this->sanitizeDecimal($row['cost']),
                'rate_a' => $this->sanitizeDecimal($row['rate_a']),
                'rate_b' => $this->sanitizeDecimal($row['rate_b']),
                'rate_c' => $this->sanitizeDecimal($row['rate_c']),
                'p_rate_discount' => $this->sanitizeDecimal($row['p_rate_discount']),
                'item_special_discount' => $this->sanitizeDecimal($row['item_special_discount']),
                'special_discount' => $this->sanitizeDecimal($row['special_discount']),
                'quantity_discount' => $this->sanitizeDecimal($row['quantity_discount']),
                'max_discount' => $this->sanitizeDecimal($row['max_discount']),
                'min_margin_disc' => $this->sanitizeDecimal($row['min_margin_disc']),
                'general_discount' => $this->sanitizeDecimal($row['discount']),
                'free_schema' => trim($row['free_schema'] ?? '') ?: null,
                'packing_desc' => trim($row['packing'] ?? '') ?: null,
                'unit' => trim($row['unit'] ?? '') ?: null,
                'secondary_unit' => trim($row['secondary'] ?? '') ?: null,
                'conversion_factor' => max(1, $this->sanitizeInt($row['conversion'])),
                'is_loose_sellable' => $isLoose,
                'local_tax' => null,
                'central_tax' => null,
                'sgst' => $this->sanitizeDecimal($row['sgst']),
                'cgst' => $this->sanitizeDecimal($row['cgst']),
                'igst' => $this->sanitizeDecimal($row['igst']),
                'csr' => $this->sanitizeDecimal($row['csr']),
                'min_stock_level' => $this->sanitizeInt($row['minimum_qty']),
                'max_stock_level' => $this->sanitizeInt($row['maximum_qty']),
                'reorder_quantity' => $this->sanitizeInt($row['reorder_qty']),
                'shelflife' => $this->parseShelflife($row['shelflife']),
                'reorder_days' => $this->sanitizeInt($row['reorder_days']) ?: null,
                'fast_search_index' => trim($row['fast_search'] ?? '') ?: null,
                'ap_remark' => trim($row['ap_remark'] ?? '') ?: null,
                'images' => !empty($images) ? json_encode($images) : null,
                'is_active' => $isActive,
                'hide' => $hide,
                'product_type' => trim($row['type'] ?? '') ?: null,
                'is_commissionable' => false,
                'is_banned' => false,
                'created_at' => $row['created_date'] ?? now(),
                'updated_at' => $row['last_updated_date'] ?? now(),
            ];

            DB::table('products')->insert($data);
            $count++;

            if ($count % 500 === 0) {
                $this->output->write('.');
            }
        }

        $this->info("\n  Migrated {$count} products" . ($skipped ? " (skipped {$skipped} existing)" : "") . ".");
    }

    private function resolveCompanyId($value, int $fallback): int
    {
        $v = trim((string)$value);
        if ($v === '' || $v === '0') {
            return $fallback;
        }
        if (is_numeric($v)) {
            $exists = DB::table('company_masters')->where('id', (int)$v)->exists();
            return $exists ? (int)$v : $fallback;
        }
        return $fallback;
    }

    private function resolveSaltId($value, int $fallback): int
    {
        $v = trim((string)$value);
        if ($v === '' || $v === '0') {
            return $fallback;
        }
        if (is_numeric($v)) {
            $exists = DB::table('salt_masters')->where('id', (int)$v)->exists();
            return $exists ? (int)$v : $fallback;
        }
        return $fallback;
    }

    private function resolveHsnId($value, int $fallback): int
    {
        $v = trim((string)$value);
        if ($v === '' || $v === '0') {
            return $fallback;
        }
        if (is_numeric($v) && isset($this->hsnIdMap[(int)$v])) {
            return $this->hsnIdMap[(int)$v];
        }
        return $fallback;
    }

    private function resolveCategoryId($value): int
    {
        $v = strtoupper(trim((string)$value));
        return $this->categoryMap[$v] ?? $this->categoryMap[''];
    }

    private function resolveBoxSizeId($value, int $fallback): int
    {
        $v = trim((string)$value);
        if ($v === '') {
            return $fallback;
        }
        return $this->boxSizeMap[$v] ?? $fallback;
    }
}
