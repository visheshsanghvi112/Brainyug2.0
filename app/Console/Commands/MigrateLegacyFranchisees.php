<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\LegacySqlReader;
use Illuminate\Support\Facades\DB;

class MigrateLegacyFranchisees extends Command
{
    protected $signature = 'erp:migrate-legacy-franchisees
        {--ho-file= : Path to pharmaer_pharmaerp.sql (HO database)}
        {--fran-file= : Path to genericp_franchisee.sql (Franchisee database)}
        {--fresh : Wipe existing districts and franchisees before migrating}';

    protected $description = 'Migrate legacy districts + franchisees + user accounts from legacy SQL dumps.';

    public function handle()
    {
        $hoFile = $this->option('ho-file') ?: base_path('../pharmaer_pharmaerp.sql');
        $franFile = $this->option('fran-file') ?: base_path('../genericp_franchisee.sql');

        if (!file_exists($hoFile)) {
            $this->error("HO SQL file not found: {$hoFile}");
            return Command::FAILURE;
        }
        if (!file_exists($franFile)) {
            $this->error("Franchisee SQL file not found: {$franFile}");
            return Command::FAILURE;
        }

        $this->info("Starting legacy franchisee migration...");

        DB::beginTransaction();
        try {
            $this->migrateDistricts($hoFile);
            $this->migrateFranchisees($franFile);
            DB::commit();
            $this->info("Franchisee migration completed successfully.");
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Migration failed: " . $e->getMessage());
            $this->error($e->getFile() . ':' . $e->getLine());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Migrate tbl_district → districts, preserving districtcode as ID.
     * Source: HO database (canonical 591 districts across 36 states).
     */
    private function migrateDistricts(string $filePath): void
    {
        $this->info("Migrating tbl_district → districts...");

        if ($this->option('fresh')) {
            $this->warn("  --fresh: Wiping existing districts...");
            DB::table('districts')->delete();
        }

        $stateIds = DB::table('states')->pluck('id')->flip()->toArray();
        $existingIds = DB::table('districts')->pluck('id')->flip()->toArray();
        $count = 0;
        $skipped = 0;

        foreach (LegacySqlReader::streamTableRows($filePath, 'tbl_district') as $row) {
            $districtCode = (int) $row['districtcode'];
            $districtName = trim($row['districtname'] ?? '');
            $stateCode = (int) ($row['statecode'] ?? 0);

            if ($districtCode <= 0 || $districtName === '') {
                $skipped++;
                continue;
            }

            if (isset($existingIds[$districtCode])) {
                $skipped++;
                continue;
            }

            if (!isset($stateIds[$stateCode])) {
                $this->warn("  District {$districtCode} ({$districtName}) references unknown state {$stateCode}, skipping.");
                $skipped++;
                continue;
            }

            DB::table('districts')->insert([
                'id'         => $districtCode,
                'name'       => $districtName,
                'state_id'   => $stateCode,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $existingIds[$districtCode] = true;
            $count++;
        }

        $this->info("  Migrated {$count} districts" . ($skipped ? " (skipped {$skipped})" : "") . ".");
    }

    /**
     * Migrate tbl_franchisee → franchisees, preserving franch_id as ID and franch_shopcode as shop_code.
     * Source: Franchisee database.
     */
    private function migrateFranchisees(string $franFile): void
    {
        $this->info("Migrating tbl_franchisee → franchisees...");

        if ($this->option('fresh')) {
            $this->warn("  --fresh: Wiping existing franchisees...");
            DB::table('franchisees')->delete();
        }

        $stateIds = DB::table('states')->pluck('id')->flip()->toArray();
        $districtIds = DB::table('districts')->pluck('id')->flip()->toArray();
        $existingIds = DB::table('franchisees')->pluck('id')->flip()->toArray();
        $existingCodes = DB::table('franchisees')->whereNotNull('shop_code')->pluck('shop_code')->flip()->toArray();
        $count = 0;
        $skipped = 0;
        $seenIds = [];

        foreach (LegacySqlReader::streamTableRows($franFile, 'tbl_franchisee') as $row) {
            $franchId = (int) $row['franch_id'];

            // Skip duplicates (franchisee dump may contain dupe sections)
            if ($franchId <= 0 || isset($seenIds[$franchId])) {
                $skipped++;
                continue;
            }
            $seenIds[$franchId] = true;

            if (isset($existingIds[$franchId])) {
                $skipped++;
                continue;
            }

            $shopCode = trim($row['franch_shopcode'] ?? '');
            $shopName = trim($row['franch_shop_name'] ?? '');
            $ownerName = trim($row['franch_owner_name'] ?? '');

            if ($shopName === '' && $ownerName === '') {
                $skipped++;
                continue;
            }

            // Avoid shop_code uniqueness violations from duplicates in dump
            if ($shopCode !== '' && isset($existingCodes[$shopCode])) {
                $this->warn("  Duplicate shop_code {$shopCode} for franch_id {$franchId}, clearing code.");
                $shopCode = '';
            }

            $stateId = (int) ($row['franch_state_id'] ?? 0);
            $districtId = (int) ($row['franch_district_id'] ?? 0);

            // Map legacy status
            $legacyStatus = trim($row['franch_status'] ?? '0');
            $legacyMenu = trim($row['franch_status_menu'] ?? '0');
            $status = $this->mapStatus($legacyStatus, $legacyMenu);

            // Parse dates safely
            $createdAt = $this->parseDate($row['franch_created_date'] ?? '');
            $activatedAt = $this->parseDate($row['activated_date'] ?? '');
            $deactivatedAt = $this->parseDate($row['deactivated_date'] ?? '');

            // Parse lat/long from combined field
            $latlong = trim($row['franch_location_latlong'] ?? '');
            [$lat, $lng] = $this->parseLatLong($latlong);

            // Parse DOB
            $dob = $this->parseDate($row['franch_dob'] ?? '');

            $data = [
                'id'                => $franchId,
                'shop_code'         => $shopCode ?: null,
                'shop_name'         => $shopName ?: ($ownerName ?: 'Unknown Shop #' . $franchId),
                'shop_type'         => 'franchise',
                'owner_name'        => $ownerName ?: $shopName,
                'owner_title'       => 'Mr',
                'partner_name'      => trim($row['franch_partner_name'] ?? '') ?: null,
                'owner_dob'         => $dob,
                'owner_age'         => ($age = (int) ($row['franch_age'] ?? 0)) > 0 && $age < 150 ? $age : null,
                'education'         => trim($row['franch_education'] ?? '') ?: null,
                'occupation'        => trim($row['franch_ownoccupation'] ?? '') ?: null,
                'email'             => $this->sanitizeEmail($row['franch_email'] ?? ''),
                'mobile'            => substr(trim($row['franch_mobile_no'] ?? ''), 0, 15) ?: '0000000000',
                'whatsapp'          => substr(trim($row['franch_whatsno'] ?? ''), 0, 15) ?: null,
                'alternate_phone'   => substr(trim($row['franch_landline'] ?? ''), 0, 15) ?: null,
                'address'           => trim($row['franch_address'] ?? '') ?: null,
                'state_id'          => isset($stateIds[$stateId]) ? $stateId : null,
                'district_id'       => isset($districtIds[$districtId]) ? $districtId : null,
                'city_id'           => null, // Cities not migrated yet (MH-only, unreliable data)
                'other_city'        => trim($row['franch_other_city'] ?? '') ?: null,
                'pincode'           => substr(trim($row['franch_pincode'] ?? ''), 0, 10) ?: null,
                'latitude'          => $lat,
                'longitude'         => $lng,
                'residence_address' => trim($row['franch_res_address'] ?? '') ?: null,
                'residence_from'    => trim($row['franch_residence_from'] ?? '') ?: null,
                'distance_from_shop' => trim($row['franch_distance'] ?? '') ?: null,
                'dl_number_20b'     => trim($row['franch_dlno_first'] ?? '') ?: null,
                'dl_number_21b'     => trim($row['franch_dlno_second'] ?? '') ?: null,
                'dl_number_third'   => trim($row['franch_dlno_third'] ?? '') ?: null,
                'bank_name'         => trim($row['bank_name'] ?? '') ?: null,
                'bank_account_holder' => trim($row['holder_name'] ?? '') ?: null,
                'utr_number'        => trim($row['franch_utr'] ?? '') ?: null,
                'transaction_date'  => $this->parseDate($row['transaction_date'] ?? ''),
                'investment_amount' => $this->sanitizeDecimal($row['amount'] ?? ''),
                'documents'         => $this->buildDocumentsJson($row),
                'status'            => $status,
                'activated_at'      => $status === 'active' ? ($activatedAt ? $activatedAt . ' 00:00:00' : $createdAt) : null,
                'deactivated_at'    => $status === 'suspended' && $deactivatedAt ? $deactivatedAt . ' 00:00:00' : null,
                'rejection_reason'  => $legacyStatus === '2' ? (trim($row['franch_ban_reason'] ?? '') ?: 'Legacy rejection') : null,
                'created_at'        => $createdAt ?: now(),
                'updated_at'        => $this->parseDate($row['franch_updated_date'] ?? '') ?: now(),
            ];

            DB::table('franchisees')->insert($data);

            if ($shopCode !== '') {
                $existingCodes[$shopCode] = true;
            }
            $count++;

            if ($count % 100 === 0) {
                $this->output->write('.');
            }
        }

        $this->newLine();

        // Summary
        $active = DB::table('franchisees')->where('status', 'active')->count();
        $pending = DB::table('franchisees')->whereIn('status', ['registered', 'enquiry'])->count();
        $rejected = DB::table('franchisees')->where('status', 'rejected')->count();
        $suspended = DB::table('franchisees')->where('status', 'suspended')->count();
        $withCode = DB::table('franchisees')->whereNotNull('shop_code')->where('shop_code', '!=', '')->count();

        $this->info("  Migrated {$count} franchisees" . ($skipped ? " (skipped {$skipped})" : ""));
        $this->table(
            ['Status', 'Count'],
            [
                ['Active', $active],
                ['Pending (registered/enquiry)', $pending],
                ['Rejected', $rejected],
                ['Suspended', $suspended],
                ['With GPM shop_code', $withCode],
            ]
        );
    }

    private function mapStatus(string $franchStatus, string $menuStatus): string
    {
        // franch_status: 0=pending, 1=accepted, 2=rejected, 3=enquiry
        // franch_status_menu: 0=inactive, 1=active
        return match ($franchStatus) {
            '1' => $menuStatus === '1' ? 'active' : 'approved',
            '2' => 'rejected',
            '3' => 'enquiry',
            default => 'registered',
        };
    }

    private function parseDate(string $value): ?string
    {
        $v = trim($value);
        if ($v === '' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') {
            return null;
        }
        try {
            $dt = new \DateTime($v);
            // Sanity: reject dates before 2000 or after 2030
            $year = (int) $dt->format('Y');
            if ($year < 1950 || $year > 2030) return null;
            return $dt->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function parseLatLong(string $value): array
    {
        if ($value === '' || !str_contains($value, ',')) {
            return [null, null];
        }
        $parts = explode(',', $value, 2);
        $lat = is_numeric(trim($parts[0])) ? round((float) trim($parts[0]), 7) : null;
        $lng = is_numeric(trim($parts[1] ?? '')) ? round((float) trim($parts[1]), 7) : null;
        return [$lat, $lng];
    }

    private function sanitizeEmail(string $value): ?string
    {
        $email = strtolower(trim($value));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return $email;
    }

    private function sanitizeDecimal($value): ?float
    {
        if ($value === null || trim((string) $value) === '') return null;
        $val = str_replace(',', '', trim((string) $value));
        return is_numeric($val) ? round((float) $val, 2) : null;
    }

    private function buildDocumentsJson(array $row): ?string
    {
        $docs = [];
        $addressProof = trim($row['address_proof'] ?? '');
        $idProof = trim($row['id_proof'] ?? '');
        $image = trim($row['franch_image'] ?? '');

        if ($addressProof !== '') $docs['address_proof'] = $addressProof;
        if ($idProof !== '') $docs['id_proof'] = $idProof;
        if ($image !== '') $docs['photo'] = $image;

        return !empty($docs) ? json_encode($docs) : null;
    }
}
