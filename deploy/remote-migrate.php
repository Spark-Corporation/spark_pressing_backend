<?php

if (! hash_equals('__TOKEN__', (string) ($_GET['token'] ?? ''))) {
    http_response_code(404);
    exit;
}

$storage = dirname(__DIR__).'/storage';
foreach ([
    $storage.'/framework/cache/data',
    $storage.'/framework/sessions',
    $storage.'/framework/views',
    $storage.'/logs',
    $storage.'/app/public',
    dirname(__DIR__).'/bootstrap/cache',
] as $dir) {
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$status = $kernel->call('migrate', ['--force' => true]);
echo $kernel->output();

if ($status !== 0) {
    http_response_code(500);
}
