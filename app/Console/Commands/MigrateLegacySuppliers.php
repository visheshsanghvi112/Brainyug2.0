<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\LegacySqlReader;
use Illuminate\Support\Facades\DB;

class MigrateLegacySuppliers extends Command
{
    protected $signature = 'erp:migrate-legacy-suppliers {--file= : Absolute path to pharmaer_pharmaerp.sql} {--fresh : Wipe existing suppliers before migrating}';
    protected $description = 'Migrate legacy states + vendor/supplier data from tbl_state and create_new_ledger.';

    public function handle()
    {
        $filePath = $this->option('file') ?: base_path('../pharmaer_pharmaerp.sql');

        if (!file_exists($filePath)) {
            $this->error("SQL file not found at: {$filePath}");
            return Command::FAILURE;
        }

        $this->info("Starting legacy states + supplier migration...");

        DB::beginTransaction();
        try {
            $this->migrateStates($filePath);
            $this->migrateSuppliers($filePath);
            DB::commit();
            $this->info("Migration completed successfully.");
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Migration failed: " . $e->getMessage());
            $this->error("Line: " . $e->getFile() . ':' . $e->getLine());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Migrate tbl_state → states, preserving statecode as ID.
     */
    private function migrateStates(string $filePath): void
    {
        $this->info("Migrating tbl_state → states...");

        $existingIds = DB::table('states')->pluck('id')->flip()->toArray();
        $count = 0;
        $skipped = 0;

        foreach (LegacySqlReader::streamTableRows($filePath, 'tbl_state') as $row) {
            $stateCode = (int) $row['statecode'];
            $stateName = trim($row['statename'] ?? '');

            if ($stateCode <= 0 || $stateName === '') {
                $skipped++;
                continue;
            }

            if (isset($existingIds[$stateCode])) {
                $skipped++;
                continue;
            }

            // GST state codes are the statecode zero-padded to 2 digits
            $gstCode = str_pad((string) $stateCode, 2, '0', STR_PAD_LEFT);

            DB::table('states')->insert([
                'id'         => $stateCode,
                'name'       => $stateName,
                'gst_code'   => $gstCode,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $count++;
        }

        $this->info("  Migrated {$count} states" . ($skipped ? " (skipped {$skipped})" : "") . ".");
    }

    /**
     * Migrate create_new_ledger → suppliers.
     * Only supplier/creditor rows (account_group NOT in '3' sundry debtors/retailers).
     * Rows with account_group=3 are franchisees — they go to the franchisees table.
     */
    private function migrateSuppliers(string $filePath): void
    {
        $this->info("Migrating create_new_ledger → suppliers (excluding franchisees)...");

        if ($this->option('fresh')) {
            $this->warn("  --fresh: Wiping existing suppliers...");
            DB::table('suppliers')->delete();
            $this->info("  Suppliers table wiped.");
        }

        // Build state lookup: statecode → state id (they should match, but be safe)
        $stateMap = DB::table('states')->pluck('id', 'id')->toArray();

        $existingIds = DB::table('suppliers')->pluck('id')->flip()->toArray();
        $count = 0;
        $skipped = 0;

        // Supplier account_groups: 4 (sundry creditor), 5 (expenses payable),
        // 6 (field staff), 7 (sundry creditors - suppliers)
        $supplierAccountGroups = ['4', '5', '6', '7'];

        foreach (LegacySqlReader::streamTableRows($filePath, 'create_new_ledger') as $row) {
            $ledId = (int) $row['led_id'];
            $accountGroup = trim($row['account_group'] ?? '');

            // Skip franchisees (account_group=3 = sundry debtors / retailers)
            if (!in_array($accountGroup, $supplierAccountGroups)) {
                $skipped++;
                continue;
            }

            if (isset($existingIds[$ledId])) {
                $skipped++;
                continue;
            }

            $name = trim($row['ledger_name'] ?? '');
            if ($name === '') {
                $skipped++;
                continue;
            }

            // Extract GST
            $gst = trim($row['gstin'] ?? '') ?: trim($row['gst'] ?? '');

            // Map state: legacy stores statecode as string in 'state' column
            $legacyState = (int) trim($row['state'] ?? '0');
            $stateId = isset($stateMap[$legacyState]) ? $legacyState : null;

            $code = 'V-' . $ledId;

            $data = [
                'id'             => $ledId,
                'name'           => $name,
                'code'           => $code,
                'contact_person' => trim($row['contact_person'] ?? '') ?: null,
                'phone'          => substr(trim($row['phone_no'] ?? ''), 0, 15) ?: null,
                'email'          => trim($row['email'] ?? '') ?: null,
                'address'        => trim($row['address'] ?? '') ?: null,
                'state_id'       => $stateId,
                'district_id'    => null,
                'pincode'        => substr(trim($row['pincode'] ?? ''), 0, 10) ?: null,
                'gst_number'     => $gst ? substr($gst, 0, 20) : null,
                'pan_number'     => substr(trim($row['it_pan_no'] ?? ''), 0, 12) ?: null,
                'dl_number'      => trim($row['dl_no'] ?? '') ?: null,
                'bank_name'      => null,
                'bank_account_number' => null,
                'bank_ifsc'      => null,
                'credit_limit'   => $this->sanitizeDecimal($row['create_amount'] ?? '0'),
                'credit_days'    => max(0, (int) ($row['credit_days_with_interest'] ?? 30)),
                'is_active'      => true,
                'created_at'     => now(),
                'updated_at'     => now(),
            ];

            DB::table('suppliers')->insert($data);
            $count++;

            if ($count % 50 === 0) {
                $this->output->write('.');
            }
        }

        $this->info("\n  Migrated {$count} suppliers" . ($skipped ? " (skipped {$skipped})" : "") . ".");
    }

    private function sanitizeDecimal($value): float
    {
        if ($value === null || trim((string) $value) === '') {
            return 0.00;
        }
        $val = str_replace(',', '', trim((string) $value));
        return is_numeric($val) ? round((float) $val, 2) : 0.00;
    }
}
