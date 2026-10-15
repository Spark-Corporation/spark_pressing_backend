<?php

/**
 * Génère un .env local en CI (validation / artisan local).
 * Ne pas uploader ce fichier en prod : le .env serveur est géré à part
 * (mots de passe avec # $ etc.).
 */
declare(strict_types=1);

$template = file_get_contents(__DIR__.'/env.production.template');
if ($template === false) {
    fwrite(STDERR, "Template env.production.template introuvable\n");
    exit(1);
}

/**
 * Quote for dotenv using single quotes (literal # and $ — ex: ApiSpec13#$).
 */
$quote = static function (string $value): string {
    // In single-quoted dotenv values, only ' itself is problematic; wrap safely.
    if (! str_contains($value, "'")) {
        return "'{$value}'";
    }

    // Fallback: double quotes with escapes.
    $escaped = str_replace(
        ['\\', '"', "\n", "\r", "\t", '$'],
        ['\\\\', '\\"', '\\n', '\\r', '\\t', '\\$'],
        $value
    );

    return '"'.$escaped.'"';
};

$env = static function (string $key, string $default = '') use ($quote): string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $default;
    }

    return $quote($value);
};

$appKey = getenv('APP_KEY');
if ($appKey === false || $appKey === '') {
    fwrite(STDERR, "APP_KEY manquant\n");
    exit(1);
}
$appKey = trim($appKey, " \t\"'");

$map = [
    '__APP_KEY__' => $quote($appKey),
    '__APP_URL__' => $env('APP_URL', 'https://pressing-api.pressingelegance.com'),
    '__CORS_ALLOWED_ORIGINS__' => $env('CORS_ALLOWED_ORIGINS', '*'),
    '__DB_HOST__' => $env('DB_HOST', 'localhost'),
    '__DB_DATABASE__' => $env('DB_DATABASE', 'sparqrqm_pressing_api'),
    '__DB_USERNAME__' => $env('DB_USERNAME', 'sparqrqm_api'),
    '__DB_PASSWORD__' => $env('DB_PASSWORD'),
];

$target = dirname(__DIR__).'/.env';
if (file_put_contents($target, strtr($template, $map)) === false) {
    fwrite(STDERR, "Impossible d'écrire {$target}\n");
    exit(1);
}

echo "Wrote {$target} (CI only — not deployed)\n";
