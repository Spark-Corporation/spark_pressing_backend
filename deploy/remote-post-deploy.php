<?php

/**
 * One-shot post-deploy runner.
 * Upload à la RACINE Laravel (docroot), pas dans public/ — évite les réécritures .htaccess.
 */
declare(strict_types=1);

set_time_limit(300);
ini_set('memory_limit', '512M');
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$report = [
    'ok' => true,
    'steps' => [],
    'checks' => [],
    'php' => PHP_VERSION,
];

$emit = static function (array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

register_shutdown_function(static function () use (&$report, $emit): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (! in_array($err['type'], $fatal, true)) {
        return;
    }
    $report['ok'] = false;
    $report['steps'][] = [
        'step' => 'fatal',
        'ok' => false,
        'message' => $err['message'].' in '.$err['file'].':'.$err['line'],
    ];
    if (! headers_sent()) {
        $emit($report, 500);
    }
});

if (! hash_equals('__TOKEN__', (string) ($_GET['token'] ?? ''))) {
    $emit(['ok' => false, 'error' => 'not_found'], 404);
}

$root = is_file(__DIR__.'/artisan') ? __DIR__ : dirname(__DIR__);
$storage = $root.'/storage';

$fail = static function (string $step, string $message, int $code = 500) use (&$report, $emit): void {
    $report['ok'] = false;
    $report['steps'][] = ['step' => $step, 'ok' => false, 'message' => $message];
    $emit($report, $code);
};

$report['checks']['root'] = $root;
$report['checks']['script'] = __FILE__;

foreach ([
    $storage.'/framework/cache/data',
    $storage.'/framework/sessions',
    $storage.'/framework/views',
    $storage.'/logs',
    $storage.'/app/public',
    $root.'/bootstrap/cache',
] as $dir) {
    if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
        $fail('directories', "Impossible de créer {$dir}");
    }
    @chmod($dir, 0775);
}
$report['steps'][] = ['step' => 'directories', 'ok' => true];

if (! is_file($root.'/.env')) {
    $fail('env', 'Fichier .env absent à la racine Laravel ('.$root.')');
}
$report['steps'][] = ['step' => 'env', 'ok' => true, 'bytes' => filesize($root.'/.env')];

if (! is_file($root.'/vendor/autoload.php')) {
    $fail('vendor', 'vendor/autoload.php absent');
}

try {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    /** @var \Illuminate\Contracts\Console\Kernel $kernel */
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
} catch (Throwable $e) {
    $fail('bootstrap', $e->getMessage());
}
$report['steps'][] = ['step' => 'bootstrap', 'ok' => true];

$runArtisan = static function (string $command, array $params = [], bool $optional = false) use ($kernel, &$report, $fail): void {
    try {
        $status = $kernel->call($command, $params);
        $output = trim($kernel->output());
        $report['steps'][] = [
            'step' => $command,
            'ok' => $status === 0,
            'status' => $status,
            'output' => $output !== '' ? mb_substr($output, 0, 4000) : null,
        ];
        if ($status !== 0 && ! $optional) {
            $fail($command, $output !== '' ? $output : "Commande {$command} en échec ({$status})");
        }
    } catch (Throwable $e) {
        if ($optional) {
            $report['steps'][] = [
                'step' => $command,
                'ok' => true,
                'message' => 'ignoré: '.$e->getMessage(),
            ];

            return;
        }
        $fail($command, $e->getMessage());
    }
};

$runArtisan('config:clear');
$runArtisan('cache:clear');
$runArtisan('route:clear');
$runArtisan('view:clear');
$runArtisan('migrate', ['--force' => true]);
$runArtisan('db:seed', ['--force' => true]);

// storage:link souvent déjà présent / interdit sur mutualisé — non bloquant
try {
    $status = $kernel->call('storage:link');
    $report['steps'][] = [
        'step' => 'storage:link',
        'ok' => true,
        'status' => $status,
        'output' => trim($kernel->output()) ?: null,
    ];
} catch (Throwable $e) {
    $report['steps'][] = ['step' => 'storage:link', 'ok' => true, 'message' => 'ignoré: '.$e->getMessage()];
}

try {
    $report['checks']['users_table'] = Illuminate\Support\Facades\Schema::hasTable('users');
    $report['checks']['personal_access_tokens'] = Illuminate\Support\Facades\Schema::hasTable('personal_access_tokens');
    $report['checks']['roles_table'] = Illuminate\Support\Facades\Schema::hasTable('roles');
    $report['checks']['pressings_table'] = Illuminate\Support\Facades\Schema::hasTable('pressings');

    if (! $report['checks']['users_table'] || ! $report['checks']['personal_access_tokens']) {
        $fail('schema', 'Tables critiques absentes après migrate');
    }

    $report['checks']['users_count'] = (int) Illuminate\Support\Facades\DB::table('users')->count();
    $report['checks']['admins_count'] = Illuminate\Support\Facades\Schema::hasTable('admins')
        ? (int) Illuminate\Support\Facades\DB::table('admins')->count()
        : 0;
    $report['checks']['clients_count'] = Illuminate\Support\Facades\Schema::hasTable('clients')
        ? (int) Illuminate\Support\Facades\DB::table('clients')->count()
        : 0;
} catch (Throwable $e) {
    $fail('schema', $e->getMessage());
}

$report['steps'][] = ['step' => 'complete', 'ok' => true];
$emit($report, 200);
