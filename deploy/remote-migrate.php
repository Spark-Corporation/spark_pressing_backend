<?php

if (! hash_equals('__TOKEN__', (string) ($_GET['token'] ?? ''))) {
    http_response_code(404);
    exit;
}

$db = '/home/sparqrqm/public_html/pressing-api/database/database.sqlite';
if (! is_file($db)) {
    touch($db);
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$kernel->call('migrate', ['--force' => true]);

echo 'migrated';
