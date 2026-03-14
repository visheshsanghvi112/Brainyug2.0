<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class ImpersonationAudit
{
    private static ?bool $tableExists = null;

    public static function record(array $payload): void
    {
        if (!self::isReady()) {
            return;
        }

        try {
            DB::table('impersonation_audits')->insert([
                'admin_user_id' => $payload['admin_user_id'],
                'impersonated_user_id' => $payload['impersonated_user_id'],
                'action' => $payload['action'],
                'reason' => $payload['reason'] ?? null,
                'method' => $payload['method'] ?? null,
                'path' => $payload['path'] ?? null,
                'response_status' => $payload['response_status'] ?? null,
                'ip_address' => $payload['ip_address'] ?? null,
                'user_agent' => $payload['user_agent'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Failed to write impersonation audit entry', [
                'payload' => $payload,
                'exception' => $exception,
            ]);
        }
    }

    private static function isReady(): bool
    {
        if (self::$tableExists === true) {
            return true;
        }

        try {
            self::$tableExists = Schema::hasTable('impersonation_audits');
        } catch (\Throwable $exception) {
            self::$tableExists = false;

            Log::warning('Unable to verify impersonation audit table availability', [
                'exception' => $exception,
            ]);
        }

        return self::$tableExists;
    }
}
