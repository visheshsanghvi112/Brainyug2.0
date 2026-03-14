<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if ($app->environment('production')) {
    fwrite(STDERR, "Refusing to run in production environment.\n");
    exit(1);
}

use App\Models\User;

$updated = 0;

User::query()
    ->whereNotNull('legacy_source')
    ->chunkById(200, function ($users) use (&$updated) {
        foreach ($users as $user) {
            $preferences = $user->preferences ?? [];
            data_set($preferences, 'legacy_migration.must_reset_password', true);
            data_set($preferences, 'legacy_migration.reset_forced_at', now()->toIso8601String());

            $user->forceFill([
                'preferences' => $preferences,
            ])->save();

            $updated++;
        }
    });

echo 'forced_reset_users=' . $updated . PHP_EOL;
