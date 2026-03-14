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

$u = User::where('email', 'admin@brainyug.com')->first();
if ($u) {
    $u->password = Hash::make('password');
    $u->save();
    echo "Password forcibly reset to 'password'\n";
} else {
    echo "User not found!\n";
}
