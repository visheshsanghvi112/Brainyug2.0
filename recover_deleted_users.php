<?php
/**
 * User Recovery Script
 * Recovers 12 deleted users from pharmaer_pharmaerp database
 * Maps legacy schema to modern brainyug_erp user table structure
 * 
 * Usage: php recover_deleted_users.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . "/vendor/autoload.php";
$app = require_once __DIR__ . "/bootstrap/app.php";
$app->make(Kernel::class)->bootstrap();

echo "=== User Recovery Script ===\n";
echo "This will recover 12 deleted users from the pharmaer_pharmaerp database.\n\n";

// Verify we're not in production
if ($app->environment("production")) {
    fwrite(STDERR, "ERROR: Cannot run in production environment.\n");
    exit(1);
}

try {
    // Connect to both databases
    $legacyUsers = DB::connection("mysql")
        ->select("SELECT * FROM pharmaer_pharmaerp.users ORDER BY id");
    
    echo "Found " . count($legacyUsers) . " users in legacy database.\n\n";
    
    if (empty($legacyUsers)) {
        echo "No users found to recover.\n";
        exit(0);
    }
    
    $recovered = 0;
    $skipped = 0;
    
    foreach ($legacyUsers as $legacyUser) {
        $legacyUser = (array) $legacyUser;
        
        // Skip if email already exists in brainyug_erp
        if (!empty($legacyUser["email"])) {
            $exists = DB::table("users")
                ->where("email", $legacyUser["email"])
                ->exists();
            
            if ($exists) {
                echo "[SKIP] User {$legacyUser["username"]} ({$legacyUser["email"]}) already exists.\n";
                $skipped++;
                continue;
            }
        }
        
        // Map legacy user to modern schema
        $mappedUser = [
            "id"               => $legacyUser["id"] ?? null,
            "name"             => $legacyUser["full_name"] ?? $legacyUser["name"] ?? "User " . ($legacyUser["id"] ?? "?"),
            "username"         => $legacyUser["username"] ?? null,
            "email"            => $legacyUser["email"] ?? "recovered-" . uniqid() . "@recovered.local",
            "password"         => $legacyUser["password"] ?? bcrypt("temporary"),
            "phone"            => null,
            "legacy_source"    => "pharmaer_pharmaerp",
            "legacy_user_id"   => $legacyUser["id"] ?? null,
            "legacy_type"      => $legacyUser["type"] ?? null,
            "legacy_username"  => $legacyUser["username"] ?? null,
            "parent_id"        => null,
            "franchisee_id"    => null,
            "is_active"        => 1,
            "google2fa_secret" => null,
            "preferences"      => json_encode([
                "legacy_migration" => [
                    "recovered_at" => now()->toIso8601String(),
                    "must_reset_password" => true,
                    "recovery_note" => "User recovered from deletion via pharmaer_pharmaerp backup",
                ]
            ]),
            "created_at"       => now(),
            "updated_at"       => now(),
        ];
        
        // Remove null ID to allow auto-increment if needed
        if ($mappedUser["id"] === null) {
            unset($mappedUser["id"]);
        }
        
        try {
            DB::table("users")->insert($mappedUser);
            echo "[ OK ] Recovered: {$legacyUser["username"]} ({$legacyUser["email"]})\n";
            $recovered++;
        } catch (\Exception $e) {
            echo "[ERROR] Failed to recover {$legacyUser["username"]}: {$e->getMessage()}\n";
        }
    }
    
    echo "\n=== Recovery Summary ===\n";
    echo "Recovered: $recovered users\n";
    echo "Skipped:   $skipped users (already exist)\n";
    echo "Total:     " . ($recovered + $skipped) . "\n";
    echo "\n Recovery complete!\n";
    echo "NOTE: All recovered users must reset their passwords on next login.\n";
    
} catch (\Exception $e) {
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}
