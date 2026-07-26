<?php
/**
 * Central configuration loader. Reads /.env (outside the web-exposed part of
 * the app where possible) and exposes settings via config('key.path').
 */

declare(strict_types=1);

if (defined('ASSESSMENT_CONFIG_LOADED')) {
    return;
}
define('ASSESSMENT_CONFIG_LOADED', true);

define('ASSESSMENT_ROOT', dirname(__DIR__));

function assessment_load_env(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

assessment_load_env(ASSESSMENT_ROOT . '/.env');

function env(string $key, mixed $default = null): mixed
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

$GLOBALS['__assessment_config'] = [
    'db' => [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => (int) env('DB_PORT', 3306),
        'name' => env('DB_NAME', 'assessment_platform'),
        'user' => env('DB_USER', ''),
        'pass' => env('DB_PASS', ''),
    ],
    'encryption_key' => env('APP_ENCRYPTION_KEY', ''),
    'azure' => [
        'tenant_id' => env('AZURE_TENANT_ID', ''),
        'client_id' => env('AZURE_CLIENT_ID', ''),
        'client_secret' => env('AZURE_CLIENT_SECRET', ''),
        'redirect_uri' => env('AZURE_REDIRECT_URI', ''),
    ],
    'graph' => [
        'base' => env('GRAPH_API_BASE', 'https://graph.microsoft.com/v1.0'),
        'scopes' => env('GRAPH_SCOPES', 'offline_access User.Read Files.ReadWrite'),
    ],
    'onedrive' => [
        'drive_id' => env('ONEDRIVE_DRIVE_ID', ''),
        'root_folder' => rtrim(env('ONEDRIVE_ROOT_FOLDER', '/Assessments'), '/'),
    ],
    'session' => [
        'name' => env('SESSION_NAME', 'assessment_sess'),
        'lifetime_minutes' => (int) env('SESSION_LIFETIME_MINUTES', 480),
    ],
];

/**
 * Dot-notation config accessor, e.g. config('db.host').
 */
function config(string $path, mixed $default = null): mixed
{
    $value = $GLOBALS['__assessment_config'];
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}
