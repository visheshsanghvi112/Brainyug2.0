<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if ($app->environment('production')) {
    fwrite(STDERR, "Refusing to run in production environment.\n");
    exit(1);
}

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

$users = User::all();
$count = 0;
foreach ($users as $u) {
    if (!str_starts_with($u->getRawOriginal('password'), '$2y$')) {
        DB::table('users')->where('id', $u->id)->update([
            'password' => Hash::make($u->getRawOriginal('password'))
        ]);
        $count++;
    }
}
echo "Hashed $count plaintext passwords.\n";
