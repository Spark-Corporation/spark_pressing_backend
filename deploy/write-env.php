<?php

$template = file_get_contents(__DIR__.'/env.production.template');

$quote = static function (string $value): string {
    return "'".str_replace("'", "'\\''", $value)."'";
};

$env = static function (string $key, string $default = '') use ($quote): string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $default;
    }

    return $quote($value);
};

$map = [
    '__APP_KEY__' => $env('APP_KEY'),
    '__APP_URL__' => $env('APP_URL', 'https://pressing-api.pressingelegance.com'),
    '__CORS_ALLOWED_ORIGINS__' => $env('CORS_ALLOWED_ORIGINS', '*'),
    '__DB_HOST__' => $env('DB_HOST', 'localhost'),
    '__DB_DATABASE__' => $env('DB_DATABASE', 'sparqrqm_pressing_api'),
    '__DB_USERNAME__' => $env('DB_USERNAME', 'sparqrqm_api'),
    '__DB_PASSWORD__' => $env('DB_PASSWORD'),
];

file_put_contents(dirname(__DIR__).'/.env', strtr($template, $map));
