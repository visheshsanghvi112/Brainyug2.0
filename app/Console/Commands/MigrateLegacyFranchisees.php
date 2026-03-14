<?php

namespace App\Console\Commands;

use App\Helpers\LegacySqlReader;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MigrateLegacyFranchisees extends Command
{
    private const FRANCHISE_SOURCE = 'genericp_franchisee';
    private const HO_USER_SOURCE = 'legacy_ho_users';
    private const FRAN_USER_SOURCE = 'legacy_franchise_users';

    protected $signature = 'erp:migrate-legacy-franchisees
        {--ho-file= : Path to pharmaer_pharmaerp.sql (HO database)}
        {--fran-file= : Path to genericp_franchisee.sql (Franchisee database)}
        {--fresh : Wipe existing districts and franchisees before migrating}
        {--fresh-users : Wipe previously imported legacy users, franchise staff links, and territory assignments before importing users}
        {--users-only : Skip district/franchisee migration and only import legacy users/staff}';

    protected $description = 'Migrate legacy districts, franchisees, hierarchy users, and franchisee staff from legacy SQL dumps.';

    private array $importedUserIdMap = [];
    private array $roleExistsCache = [];
    private array $franchiseeCodeCache = [];

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
            if (!$this->option('users-only')) {
                $this->migrateDistricts($hoFile);
                $this->migrateFranchisees($franFile);
            }

            $this->migrateUsers($hoFile, $franFile);
            DB::commit();
            $this->info('Franchisee and user migration completed successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Migration failed: ' . $e->getMessage());
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
                DB::table('franchisees')
                    ->where('id', $franchId)
                    ->update([
                        'legacy_source' => self::FRANCHISE_SOURCE,
                        'legacy_franchise_id' => $franchId,
                    ]);
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
                'legacy_source'     => self::FRANCHISE_SOURCE,
                'legacy_franchise_id' => $franchId,
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

    private function migrateUsers(string $hoFile, string $franFile): void
    {
        $this->info('Migrating legacy users → users...');

        if ($this->option('fresh-users')) {
            $this->wipeImportedLegacyUsers();
        }

        $this->importedUserIdMap = $this->loadImportedUserMap();

        $hoUsers = $this->collectLegacyUsers($hoFile, self::HO_USER_SOURCE);
        $staffContext = $this->collectFranchiseStaffContext($franFile);
        $franchiseUsers = $this->collectLegacyUsers($franFile, self::FRAN_USER_SOURCE);

        $importedFromHo = $this->upsertLegacyUsers($hoUsers, true);

        $importedFromFranchise = $this->upsertLegacyUsers($franchiseUsers, true);

        $this->hydrateParentLinks($importedFromHo + $importedFromFranchise);
        $this->syncRolesAndTerritories($importedFromHo + $importedFromFranchise);
        $this->rebuildHierarchyFromLegacyTables($franFile);
        $this->migrateFranchiseeStaff($staffContext['rows'], $franchiseUsers);
    }

    private function rebuildHierarchyFromLegacyTables(string $franFile): void
    {
        $this->info('Rebuilding hierarchy/territories from tbl_state_head, tbl_master_head, tbl_district_head...');

        $masterByDistrict = [];
        $updatedParents = 0;
        $updatedTerritories = 0;

        foreach (LegacySqlReader::streamTableRows($franFile, 'tbl_state_head') as $row) {
            $legacyUserId = (int) ($row['stateh_user_id'] ?? 0);
            $stateCode = (int) ($row['stateh_statecode'] ?? 0);
            $newUserId = $this->resolveImportedUserId(self::FRAN_USER_SOURCE, $legacyUserId)
                ?: $this->resolveImportedUserId(self::HO_USER_SOURCE, $legacyUserId);

            if (!$newUserId || $stateCode <= 0 || !DB::table('states')->where('id', $stateCode)->exists()) {
                continue;
            }

            DB::table('territory_assignments')->where('user_id', $newUserId)->where('territory_type', 'state')->delete();
            DB::table('territory_assignments')->updateOrInsert(
                [
                    'user_id' => $newUserId,
                    'territory_type' => 'state',
                    'territory_id' => $stateCode,
                ],
                [
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $updatedTerritories++;
        }

        foreach (LegacySqlReader::streamTableRows($franFile, 'tbl_master_head') as $row) {
            $legacyUserId = (int) ($row['masterh_user_id'] ?? 0);
            $legacyParentId = (int) ($row['masterh_statehead_id'] ?? 0);
            $districtIds = $this->parseDistrictIds((string) ($row['masterh_districtcode'] ?? ''));

            $newUserId = $this->resolveImportedUserId(self::FRAN_USER_SOURCE, $legacyUserId)
                ?: $this->resolveImportedUserId(self::HO_USER_SOURCE, $legacyUserId);

            if (!$newUserId) {
                continue;
            }

            $parentId = $this->resolveImportedUserId(self::FRAN_USER_SOURCE, $legacyParentId)
                ?: $this->resolveImportedUserId(self::HO_USER_SOURCE, $legacyParentId)
                ?: $this->resolveImportedUserIdAcrossSources($legacyParentId);

            if ($parentId && $parentId !== $newUserId) {
                DB::table('users')->where('id', $newUserId)->update(['parent_id' => $parentId]);
                $updatedParents++;
            }

            DB::table('territory_assignments')->where('user_id', $newUserId)->where('territory_type', 'district')->delete();
            foreach ($districtIds as $districtId) {
                if (!DB::table('districts')->where('id', $districtId)->exists()) {
                    continue;
                }

                DB::table('territory_assignments')->updateOrInsert(
                    [
                        'user_id' => $newUserId,
                        'territory_type' => 'district',
                        'territory_id' => $districtId,
                    ],
                    [
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

                $masterByDistrict[$districtId] = $legacyUserId;
                $updatedTerritories++;
            }
        }

        foreach (LegacySqlReader::streamTableRows($franFile, 'tbl_district_head') as $row) {
            $legacyUserId = (int) ($row['districth_user_id'] ?? 0);
            $districtCode = (int) ($row['districth_districtcode'] ?? 0);
            $legacyRegionalId = (int) ($row['district_regionalh_id'] ?? 0);

            $newUserId = $this->resolveImportedUserId(self::FRAN_USER_SOURCE, $legacyUserId)
                ?: $this->resolveImportedUserId(self::HO_USER_SOURCE, $legacyUserId);

            if (!$newUserId) {
                continue;
            }

            $effectiveParentLegacyId = $legacyRegionalId > 0
                ? $legacyRegionalId
                : ($masterByDistrict[$districtCode] ?? 0);

            $parentId = $this->resolveImportedUserId(self::FRAN_USER_SOURCE, (int) $effectiveParentLegacyId)
                ?: $this->resolveImportedUserId(self::HO_USER_SOURCE, (int) $effectiveParentLegacyId)
                ?: $this->resolveImportedUserIdAcrossSources((int) $effectiveParentLegacyId);

            if ($parentId && $parentId !== $newUserId) {
                DB::table('users')->where('id', $newUserId)->update(['parent_id' => $parentId]);
                $updatedParents++;
            }

            DB::table('territory_assignments')->where('user_id', $newUserId)->where('territory_type', 'district')->delete();
            if ($districtCode > 0 && DB::table('districts')->where('id', $districtCode)->exists()) {
                DB::table('territory_assignments')->updateOrInsert(
                    [
                        'user_id' => $newUserId,
                        'territory_type' => 'district',
                        'territory_id' => $districtCode,
                    ],
                    [
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $updatedTerritories++;
            }
        }

        $this->info("  Hierarchy rebuild updated {$updatedParents} parent links and {$updatedTerritories} territory rows.");
    }

    private function wipeImportedLegacyUsers(): void
    {
        $this->warn('  --fresh-users: wiping previously imported legacy users, staff links, and territory assignments...');

        $legacyUserIds = DB::table('users')
            ->whereNotNull('legacy_source')
            ->pluck('id')
            ->all();

        if ($legacyUserIds === []) {
            return;
        }

        DB::table('franchisee_staff')->whereIn('user_id', $legacyUserIds)->delete();
        DB::table('territory_assignments')->whereIn('user_id', $legacyUserIds)->delete();
        DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->whereIn('model_id', $legacyUserIds)
            ->delete();
        DB::table('model_has_permissions')
            ->where('model_type', User::class)
            ->whereIn('model_id', $legacyUserIds)
            ->delete();
        DB::table('users')->whereIn('id', $legacyUserIds)->delete();
    }

    private function loadImportedUserMap(): array
    {
        $map = [];

        foreach (DB::table('users')
            ->whereNotNull('legacy_source')
            ->whereNotNull('legacy_user_id')
            ->select('id', 'legacy_source', 'legacy_user_id')
            ->get() as $row) {
            $map[$row->legacy_source][(int) $row->legacy_user_id] = (int) $row->id;
        }

        return $map;
    }

    private function collectLegacyUsers(string $filePath, string $source, ?array $onlyIds = null): array
    {
        $rows = [];
        $filterIds = $onlyIds !== null ? array_flip(array_map('intval', $onlyIds)) : null;

        foreach (LegacySqlReader::streamTableRows($filePath, 'users') as $row) {
            $legacyId = (int) ($row['id'] ?? 0);
            if ($legacyId <= 0) {
                continue;
            }

            if ($filterIds !== null && !isset($filterIds[$legacyId])) {
                continue;
            }

            $role = $this->mapLegacyTypeToRole($row['type'] ?? null, $source);
            if ($role === null) {
                continue;
            }

            $row['_legacy_source'] = $source;
            $rows[$legacyId] = $row;
        }

        return $rows;
    }

    private function collectFranchiseStaffContext(string $franFile): array
    {
        $rows = [];
        $staffUserIds = [];
        $ownerUserIds = [];

        foreach (LegacySqlReader::streamTableRows($franFile, 'franchisee_users') as $row) {
            $rows[] = $row;

            $staffUserId = (int) ($row['user_id'] ?? 0);
            $ownerUserId = (int) ($row['created_by'] ?? 0);

            if ($staffUserId > 0) {
                $staffUserIds[] = $staffUserId;
            }

            if ($ownerUserId > 0) {
                $ownerUserIds[] = $ownerUserId;
            }
        }

        return [
            'rows' => $rows,
            'staff_user_ids' => array_values(array_unique($staffUserIds)),
            'owner_user_ids' => array_values(array_unique($ownerUserIds)),
        ];
    }

    private function upsertLegacyUsers(array $legacyUsers, bool $resolveParent): array
    {
        $importedRows = [];
        $created = 0;
        $updated = 0;

        foreach ($legacyUsers as $legacyId => $row) {
            $source = (string) ($row['_legacy_source'] ?? self::HO_USER_SOURCE);
            $existingId = $this->resolveImportedUserId($source, (int) $legacyId);
            $payload = $this->buildUserPayload($row, $source, $existingId);

            if ($existingId) {
                $updatePayload = $payload;
                unset($updatePayload['created_at']);

                DB::table('users')->where('id', $existingId)->update($updatePayload);
                $newUserId = $existingId;
                $updated++;
            } else {
                $newUserId = (int) DB::table('users')->insertGetId($payload);
                $created++;
            }

            $this->importedUserIdMap[$source][(int) $legacyId] = $newUserId;
            $importedRows[$newUserId] = $row;
            $importedRows[$newUserId]['_new_user_id'] = $newUserId;

            if ($resolveParent) {
                $importedRows[$newUserId]['_legacy_parent_id'] = (int) ($row['parent_id'] ?? 0);
            }
        }

        $this->info("  Imported {$created} legacy users" . ($updated ? " and updated {$updated}" : '') . '.');

        return $importedRows;
    }

    private function buildUserPayload(array $row, string $source, ?int $existingId): array
    {
        $legacyId = (int) ($row['id'] ?? 0);
        $rawUsername = trim((string) ($row['username'] ?? ''));
        $rawEmail = trim((string) ($row['email'] ?? ''));
        $name = trim((string) ($row['fullname'] ?? ''));
        $passwordHash = trim((string) ($row['password'] ?? ''));
        $franchiseeId = $this->resolveFranchiseeId((int) ($row['franch_id'] ?? 0));
        $role = $this->mapLegacyTypeToRole($row['type'] ?? null, $source);
        $fallbackFranchiseUsername = $role === 'Franchisee' ? $this->resolveFranchiseeShopCode($franchiseeId) : null;

        if ($name === '') {
            $name = $rawUsername !== '' ? $rawUsername : ('Legacy User #' . $legacyId);
        }

        $mustResetPassword = !$this->isTrustedPasswordHash($passwordHash);
        $finalPassword = $mustResetPassword ? Hash::make(Str::random(48)) : $passwordHash;

        $preferences = $this->mergePreferences(
            $existingId ? DB::table('users')->where('id', $existingId)->value('preferences') : null,
            [
                'legacy_migration' => [
                    'source' => $source,
                    'legacy_user_id' => $legacyId,
                    'legacy_type' => (string) ($row['type'] ?? ''),
                    'legacy_statecode' => (int) ($row['statecode'] ?? 0),
                    'legacy_districtcode' => (string) ($row['districtcode'] ?? ''),
                    'must_reset_password' => $mustResetPassword,
                    'password_format' => $this->passwordFormat($passwordHash),
                    'last_migrated_at' => now()->toIso8601String(),
                ],
            ]
        );

        $createdAt = $this->parseDateTime($row['created'] ?? null)
            ?? $this->parseDateTime($row['created_at'] ?? null)
            ?? now()->toDateTimeString();

        $updatedAt = $this->parseDateTime($row['updated_at'] ?? null)
            ?? $this->parseDateTime($row['modified'] ?? null)
            ?? $createdAt;

        return [
            'name' => Str::limit($name, 255, ''),
            'username' => $this->determineUniqueUsername($rawUsername !== '' ? $rawUsername : ($fallbackFranchiseUsername ?? ''), $source, $legacyId, $existingId),
            'email' => $this->determineUniqueEmail($rawEmail, $source, $legacyId, $existingId),
            'phone' => $this->sanitizePhone($row['mobileno'] ?? null),
            'password' => $finalPassword,
            'parent_id' => null,
            'franchisee_id' => $franchiseeId,
            'is_active' => $this->legacyUserIsActive($row),
            'email_verified_at' => $this->legacyUserIsActive($row) ? $createdAt : null,
            'legacy_source' => $source,
            'legacy_user_id' => $legacyId,
            'legacy_type' => (int) ($row['type'] ?? 0) ?: null,
            'legacy_username' => $rawUsername !== '' ? $rawUsername : null,
            'preferences' => $preferences,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    private function hydrateParentLinks(array $importedRows): void
    {
        foreach ($importedRows as $newUserId => $row) {
            $legacyParentId = (int) ($row['_legacy_parent_id'] ?? 0);
            if ($legacyParentId <= 0) {
                continue;
            }

            $source = (string) ($row['_legacy_source'] ?? self::HO_USER_SOURCE);
            $parentId = $this->resolveImportedUserId($source, $legacyParentId) ?: $this->resolveImportedUserIdAcrossSources($legacyParentId);

            if ($parentId && $parentId !== (int) $newUserId) {
                DB::table('users')->where('id', $newUserId)->update(['parent_id' => $parentId]);
            }
        }
    }

    private function syncRolesAndTerritories(array $importedRows): void
    {
        foreach ($importedRows as $newUserId => $row) {
            $source = (string) ($row['_legacy_source'] ?? self::HO_USER_SOURCE);
            $role = $this->mapLegacyTypeToRole($row['type'] ?? null, $source);
            if ($role !== null) {
                User::find($newUserId)?->syncRoles([$role]);
            }

            DB::table('territory_assignments')->where('user_id', $newUserId)->delete();

            $stateId = (int) ($row['statecode'] ?? 0);
            $districtIds = $this->parseDistrictIds((string) ($row['districtcode'] ?? ''));

            if ($role === 'State Head' && $stateId > 0 && DB::table('states')->where('id', $stateId)->exists()) {
                DB::table('territory_assignments')->insert([
                    'user_id' => $newUserId,
                    'territory_type' => 'state',
                    'territory_id' => $stateId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (in_array($role, ['Regional Head', 'Zonal Head', 'District Head'], true)) {
                foreach ($districtIds as $districtId) {
                    if (!DB::table('districts')->where('id', $districtId)->exists()) {
                        continue;
                    }

                    DB::table('territory_assignments')->updateOrInsert(
                        [
                            'user_id' => $newUserId,
                            'territory_type' => 'district',
                            'territory_id' => $districtId,
                        ],
                        [
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                }
            }
        }
    }

    private function migrateFranchiseeStaff(array $staffRows, array $franchiseUsers): void
    {
        $this->info('Migrating franchisee_users → franchisee_staff...');

        $migrated = 0;
        $skipped = 0;

        foreach ($staffRows as $row) {
            $legacyStaffUserId = (int) ($row['user_id'] ?? 0);
            if ($legacyStaffUserId <= 0) {
                $skipped++;
                continue;
            }

            $newUserId = $this->resolveImportedUserId(self::FRAN_USER_SOURCE, $legacyStaffUserId)
                ?: $this->resolveImportedUserId(self::HO_USER_SOURCE, $legacyStaffUserId);

            if (!$newUserId) {
                $skipped++;
                continue;
            }

            $ownerLegacyId = (int) ($row['created_by'] ?? 0);
            $ownerRow = $franchiseUsers[$ownerLegacyId] ?? null;
            $staffLegacyRow = $franchiseUsers[$legacyStaffUserId] ?? null;

            $franchiseeId = $this->resolveFranchiseeId((int) ($ownerRow['franch_id'] ?? 0))
                ?: $this->resolveFranchiseeId((int) ($staffLegacyRow['franch_id'] ?? 0));

            if (!$franchiseeId) {
                $skipped++;
                continue;
            }

            $ownerNewUserId = $ownerLegacyId > 0
                ? ($this->resolveImportedUserId(self::HO_USER_SOURCE, $ownerLegacyId)
                    ?: $this->resolveImportedUserId(self::FRAN_USER_SOURCE, $ownerLegacyId))
                : null;

            DB::table('users')->where('id', $newUserId)->update([
                'franchisee_id' => $franchiseeId,
                'parent_id' => $ownerNewUserId ?: DB::table('users')->where('id', $newUserId)->value('parent_id'),
                'phone' => $this->sanitizePhone($row['fu_mobile'] ?? null) ?: DB::table('users')->where('id', $newUserId)->value('phone'),
                'updated_at' => now(),
            ]);

            DB::table('franchisee_staff')->updateOrInsert(
                [
                    'franchisee_id' => $franchiseeId,
                    'user_id' => $newUserId,
                ],
                [
                    'designation' => 'Legacy Franchise Staff',
                    'is_active' => $this->legacyUserIsActive($staffLegacyRow ?? []),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            User::find($newUserId)?->syncRoles(['Franchisee']);
            $migrated++;
        }

        $this->info("  Migrated {$migrated} franchise staff records" . ($skipped ? " (skipped {$skipped})" : '') . '.');
    }

    private function resolveImportedUserId(string $source, int $legacyId): ?int
    {
        return $this->importedUserIdMap[$source][$legacyId] ?? null;
    }

    private function resolveImportedUserIdAcrossSources(int $legacyId): ?int
    {
        foreach ($this->importedUserIdMap as $idsBySource) {
            if (isset($idsBySource[$legacyId])) {
                return $idsBySource[$legacyId];
            }
        }

        return null;
    }

    private function resolveFranchiseeId(int $legacyFranchiseId): ?int
    {
        if ($legacyFranchiseId <= 0) {
            return null;
        }

        return DB::table('franchisees')->where('id', $legacyFranchiseId)->value('id')
            ?: DB::table('franchisees')
                ->where('legacy_source', self::FRANCHISE_SOURCE)
                ->where('legacy_franchise_id', $legacyFranchiseId)
                ->value('id');
    }

    private function mapLegacyTypeToRole($type, ?string $source = null): ?string
    {
        $source = $source ?: self::HO_USER_SOURCE;

        if ($source === self::HO_USER_SOURCE) {
            return match ((string) $type) {
                '1' => 'Admin',
                '2' => 'State Head',
                '3' => 'Regional Head',
                '4' => 'District Head',
                '5' => 'Franchisee',
                '6' => 'Distributer',
                '7' => 'Sales Team',
                '8' => 'Account',
                '9' => $this->firstAvailableRole(['Order', 'Distributer']),
                '10' => $this->firstAvailableRole(['Warehouse', 'Distributer']),
                '11' => $this->firstAvailableRole(['Inward', 'Warehouse']),
                default => null,
            };
        }

        return match ((string) $type) {
            '1' => 'Admin',
            '2' => 'State Head',
            '3' => 'Regional Head',
            '4' => 'District Head',
            '5' => 'Franchisee',
            '6' => 'Distributer',
            '7' => 'Sales Team',
            '8' => 'Zonal Head',
            '9' => 'Account',
            '10' => 'Franchisee',
            default => null,
        };
    }

    private function firstAvailableRole(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($this->roleExists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function roleExists(string $roleName): bool
    {
        if (array_key_exists($roleName, $this->roleExistsCache)) {
            return $this->roleExistsCache[$roleName];
        }

        $exists = DB::table('roles')->where('name', $roleName)->exists();
        $this->roleExistsCache[$roleName] = $exists;

        return $exists;
    }

    private function resolveFranchiseeShopCode(?int $franchiseeId): ?string
    {
        if (!$franchiseeId) {
            return null;
        }

        if (array_key_exists($franchiseeId, $this->franchiseeCodeCache)) {
            return $this->franchiseeCodeCache[$franchiseeId];
        }

        $shopCode = DB::table('franchisees')->where('id', $franchiseeId)->value('shop_code');
        $shopCode = is_string($shopCode) ? trim($shopCode) : null;
        $this->franchiseeCodeCache[$franchiseeId] = $shopCode !== '' ? $shopCode : null;

        return $this->franchiseeCodeCache[$franchiseeId];
    }

    private function legacyUserIsActive(array $row): bool
    {
        $activated = (int) ($row['activated'] ?? 1) === 1;
        $notBanned = (int) ($row['banned'] ?? 0) !== 1;
        $legacyActive = !isset($row['is_active']) || (int) $row['is_active'] === 1;

        return $activated && $notBanned && $legacyActive;
    }

    private function determineUniqueUsername(string $preferred, string $source, int $legacyId, ?int $existingId): string
    {
        $candidate = trim($preferred);
        $candidate = preg_replace('/[^A-Za-z0-9_-]/', '_', $candidate) ?: '';

        if ($candidate === '') {
            $candidate = Str::lower(Str::slug($source . '_' . $legacyId, '_'));
        }

        $base = Str::limit($candidate, 50, '');
        $candidate = $base;
        $suffix = 1;

        while ($this->usernameTakenByAnotherUser($candidate, $existingId)) {
            $tail = '_' . $legacyId . ($suffix > 1 ? '_' . $suffix : '');
            $candidate = Str::limit($base, max(1, 50 - strlen($tail)), '') . $tail;
            $suffix++;
        }

        return $candidate;
    }

    private function determineUniqueEmail(string $rawEmail, string $source, int $legacyId, ?int $existingId): string
    {
        $email = strtolower(trim($rawEmail));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = Str::lower(Str::slug($source, '_')) . '+' . $legacyId . '@migrated.local';
        }

        if (!$this->emailTakenByAnotherUser($email, $existingId)) {
            return $email;
        }

        $localPart = Str::before($email, '@');
        $domain = Str::after($email, '@');
        $suffix = 1;

        do {
            $candidate = $localPart . '+' . $legacyId . ($suffix > 1 ? '_' . $suffix : '') . '@' . $domain;
            $suffix++;
        } while ($this->emailTakenByAnotherUser($candidate, $existingId));

        return $candidate;
    }

    private function usernameTakenByAnotherUser(string $username, ?int $existingId): bool
    {
        $query = DB::table('users')->where('username', $username);
        if ($existingId) {
            $query->where('id', '!=', $existingId);
        }

        return $query->exists();
    }

    private function emailTakenByAnotherUser(string $email, ?int $existingId): bool
    {
        $query = DB::table('users')->where('email', $email);
        if ($existingId) {
            $query->where('id', '!=', $existingId);
        }

        return $query->exists();
    }

    private function sanitizePhone($value): ?string
    {
        $phone = preg_replace('/[^0-9]/', '', trim((string) ($value ?? '')));
        if ($phone === '') {
            return null;
        }

        return substr($phone, 0, 15);
    }

    private function isTrustedPasswordHash(string $hash): bool
    {
        return preg_match('/^\$2[aby]\$/', $hash) === 1;
    }

    private function passwordFormat(string $hash): string
    {
        if ($this->isTrustedPasswordHash($hash)) {
            return 'bcrypt';
        }

        if (str_starts_with($hash, '$P$') || str_starts_with($hash, '$H$')) {
            return 'phpass';
        }

        return $hash === '' ? 'missing' : 'unknown';
    }

    private function mergePreferences($existingJson, array $patch): string
    {
        $existing = [];
        if (is_string($existingJson) && $existingJson !== '') {
            $decoded = json_decode($existingJson, true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }

        $merged = array_replace_recursive($existing, $patch);

        return json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function parseDateTime($value): ?string
    {
        $v = trim((string) ($value ?? ''));
        if ($v === '' || $v === '0000-00-00 00:00:00' || $v === '0000-00-00') {
            return null;
        }

        try {
            $dt = new \DateTime($v);
            $year = (int) $dt->format('Y');
            if ($year < 1950 || $year > 2035) {
                return null;
            }

            return $dt->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function parseDistrictIds(string $value): array
    {
        $parts = array_filter(array_map('trim', explode(',', $value)), fn (string $part) => $part !== '' && is_numeric($part));

        return array_values(array_unique(array_map('intval', $parts)));
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
