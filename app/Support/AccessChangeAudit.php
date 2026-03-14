<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class AccessChangeAudit
{
    private static ?bool $tableExists = null;

    public static function record(
        User $actor,
        int $targetUserId,
        string $eventType,
        ?array $beforeState,
        ?array $afterState,
        string $summary,
        ?Request $request = null
    ): void {
        if (!self::isReady()) {
            return;
        }

        try {
            DB::table('user_access_change_audits')->insert([
                'actor_user_id' => $actor->id,
                'target_user_id' => $targetUserId,
                'event_type' => $eventType,
                'summary' => $summary,
                'before_state' => $beforeState === null ? null : json_encode($beforeState, JSON_THROW_ON_ERROR),
                'after_state' => $afterState === null ? null : json_encode($afterState, JSON_THROW_ON_ERROR),
                'meta' => json_encode([
                    'route' => $request?->route()?->getName(),
                    'method' => $request?->method(),
                    'path' => $request?->path(),
                ], JSON_THROW_ON_ERROR),
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Failed to write user access audit record', [
                'actor_user_id' => $actor->id,
                'target_user_id' => $targetUserId,
                'event_type' => $eventType,
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
            self::$tableExists = Schema::hasTable('user_access_change_audits');
        } catch (\Throwable $exception) {
            self::$tableExists = false;

            Log::warning('Unable to verify user access audit table availability', [
                'exception' => $exception,
            ]);
        }

        return self::$tableExists;
    }
}
