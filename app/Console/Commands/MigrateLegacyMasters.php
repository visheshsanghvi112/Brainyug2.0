<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\LegacySqlReader;
use App\Models\CompanyMaster;
use App\Models\HsnMaster;
use App\Models\SaltMaster;
use App\Models\ItemCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateLegacyMasters extends Command
{
    protected $signature = 'erp:migrate-legacy-masters {--file= : Absolute path to pharmaer_pharmaerp.sql}';
    protected $description = 'Safely stream and migrate legacy master data natively from SQL file without MySQL import.';

    public function handle()
    {
        $filePath = $this->option('file') ?: base_path('../pharmaer_pharmaerp.sql');

        if (!file_exists($filePath)) {
            $this->error("SQL file not found at: {$filePath}");
            return Command::FAILURE;
        }

        $this->info("Initializing wise CIA-level local migration protocol...");
        $this->info("Target SQL file: {$filePath}");

        DB::beginTransaction();
        try {
            $this->migrateCompanies($filePath);
            $this->migrateHsn($filePath);
            $this->migrateSalts($filePath);
            
            DB::commit();
            $this->info("✔ Migration executed flawlessly.");
        } catch (\Exception $e) {
            DB::rollBack();
            file_put_contents(base_path('migration_error.txt'), $e->getMessage() . "\n" . $e->getTraceAsString());
            $this->error("✖ Migration failed and rolled back. See migration_error.txt for details.");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function sanitizeDecimal($value)
    {
        if ($value === null || trim($value) === '') {
            return 0.00;
        }
        $val = str_replace(',', '', trim($value));
        return is_numeric($val) ? (float) $val : 0.00;
    }

    private function migrateCompanies($filePath)
    {
        $this->info("Migrating company_master...");
        $rows = LegacySqlReader::streamTableRows($filePath, 'company_master');
        $count = 0;

        foreach ($rows as $row) {
            // Use DB::table to preserve original legacy IDs (Eloquent guards 'id')
            DB::table('company_masters')->updateOrInsert(
                ['id' => $row['id']],
                [
                    'name' => trim($row['company_name']),
                    'preference' => trim($row['preference'] ?? ''),
                    'dump_days' => trim($row['dump_days'] ?? ''),
                    'expiry_receive_upto' => trim($row['expiry_receive_upto'] ?? ''),
                    'minimum_margin' => trim($row['minimum_margin'] ?? ''),
                    'sales_tax' => trim($row['sales_tax'] ?? ''),
                    'purchase_tax' => trim($row['purchase_tax'] ?? ''),
                ]
            );
            $count++;
            if ($count % 100 === 0) {
                $this->output->write('.');
            }
        }
        $this->info("\n✔ Migrated {$count} companies.");
    }

    private function migrateHsn($filePath)
    {
        $this->info("Migrating hsn_master...");
        $rows = LegacySqlReader::streamTableRows($filePath, 'hsn_master');
        $count = 0;

        // Note: New schema has hsn_name and unit, and uses Decimal fields for tax correctly
        foreach ($rows as $row) {
            $hsnCode = trim($row['hsn_code']);
            // Skip duplicate hsn_code that maps to a different ID
            $existing = DB::table('hsn_masters')->where('hsn_code', $hsnCode)->first();
            if ($existing && $existing->id != $row['id']) {
                continue;
            }

            // Use DB::table to preserve original legacy IDs (Eloquent guards 'id')
            DB::table('hsn_masters')->updateOrInsert(
                ['id' => $row['id']],
                [
                    'hsn_name' => trim($row['hsn_name'] ?? ('HSN ' . $row['hsn_code'])),
                    'hsn_code' => trim($row['hsn_code']),
                    'sgst_percent' => $this->sanitizeDecimal($row['sgst']),
                    'cgst_percent' => $this->sanitizeDecimal($row['cgst']),
                    'igst_percent' => $this->sanitizeDecimal($row['igst']),
                    'unit' => trim($row['unit'] ?? ''), 
                ]
            );
            $count++;
            if ($count % 100 === 0) {
                $this->output->write('.');
            }
        }
        $this->info("\n✔ Migrated {$count} HSN codes.");
    }

    private function migrateSalts($filePath)
    {
        $this->info("Migrating salt_master...");
        $rows = LegacySqlReader::streamTableRows($filePath, 'salt_master');
        $count = 0;

        foreach ($rows as $row) {
            // Note: Legacy boolean flags were string ('Yes'/'No' or 'Continue')
            $narcotic = (strtolower(trim($row['narcotic'])) === 'yes' || trim($row['narcotic']) === '1');
            $scheduleH = (strtolower(trim($row['scheduleH'] ?? '')) === 'yes' || trim($row['scheduleH'] ?? '') === '1');
            $scheduleH1 = (strtolower(trim($row['scheduleH1'] ?? '')) === 'yes' || trim($row['scheduleH1'] ?? '') === '1');

            // Use DB::table to preserve original legacy IDs (Eloquent guards 'id')
            DB::table('salt_masters')->updateOrInsert(
                ['id' => $row['id']],
                [
                    'name' => substr(trim($row['salt_name']), 0, 255),
                    'indication' => substr(trim($row['indication'] ?? ''), 0, 255),
                    'dosage' => substr(trim($row['dosage'] ?? ''), 0, 255),
                    'side_effects' => substr(trim($row['side_effects'] ?? ''), 0, 255),
                    'special_precaution' => substr(trim($row['special_precaution'] ?? ''), 0, 255),
                    'drug_interaction' => substr(trim($row['drug_interaction'] ?? ''), 0, 255),
                    'is_narcotic' => $narcotic,
                    'schedule_h' => $scheduleH,
                    'schedule_h1' => $scheduleH1,
                    'note' => substr(trim($row['note'] ?? ''), 0, 255),
                    'maximum_rate' => substr(trim($row['maximum_rate'] ?? ''), 0, 255),
                    'continued' => trim($row['continued'] ?? ''),
                    'prohibited' => trim($row['prohibited'] ?? ''),
                    'legacy_category_id' => $row['category_id'] ? (int)$row['category_id'] : null,
                    'legacy_sub_category_id' => isset($row['sub_category_id']) && $row['sub_category_id'] ? (int)$row['sub_category_id'] : null,
                ]
            );
            $count++;
            if ($count % 500 === 0) {
                $this->output->write('.');
            }
        }
        $this->info("\n✔ Migrated {$count} salt compositions.");
    }
}
