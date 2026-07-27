<?php
/**
 * Central configuration for the assessment platform. Non-secret settings
 * (DB host/name/user, tenant id, client id) default to the live QMHS
 * Portal deployment's own values directly, so the app runs with minimal
 * setup. Actual secrets (DB password, Azure client secret, the AES
 * encryption key) are never hardcoded here - they're loaded from the
 * root-level .env.php via includes/secrets.php (shared with
 * auth_handler.php) so they never end up in source control. See
 * /.env.php.example for the keys that file must define.
 */

declare(strict_types=1);

if (defined('ASSESSMENT_CONFIG_LOADED')) {
    return;
}
define('ASSESSMENT_CONFIG_LOADED', true);

define('ASSESSMENT_ROOT', dirname(__DIR__));

require_once ASSESSMENT_ROOT . '/includes/secrets.php';

function env(string $key, ?string $default = null): ?string
{
    return qmhs_env($key, $default);
}

$GLOBALS['__assessment_config'] = [
    'db' => [
        'host' => env('DB_HOST', 'localhost'),
        'port' => (int) env('DB_PORT', '3306'),
        'name' => env('DB_NAME', 'u781387176_assessment'),
        'user' => env('DB_USER', 'u781387176_assessadmin'),
        'pass' => env('ASSESSMENT_DB_PASSWORD'),
    ],

    // AES-256-GCM key used only for mark scheme / model answer encryption
    // (see models/Crypto.php). Must be set in .env.php before first use - once
    // data is encrypted with it, changing it makes that data unreadable.
    'encryption_key' => env('ASSESSMENT_ENCRYPTION_KEY'),

    // Same Microsoft Entra ID app registration the root auth_handler.php
    // uses, purely so this service can silently refresh the session's
    // Graph access token when it expires - the assessment app never runs
    // its own OAuth login flow. Tenant/client id are not secret (they're
    // visible in the browser's OAuth redirect URL already); only the
    // client secret is kept out of source control.
    'azure' => [
        'tenant_id' => env('AZURE_TENANT_ID', '3df55413-ced7-4b48-8f6e-30bc4dac254f'),
        'client_id' => env('AZURE_CLIENT_ID', 'eb393a58-2841-4188-9e8e-0dd26026b2e6'),
        'client_secret' => env('AZURE_CLIENT_SECRET'),
    ],

    'graph' => [
        'base' => env('GRAPH_API_BASE', 'https://graph.microsoft.com/v1.0'),
    ],

    'onedrive' => [
        'drive_id' => env('ASSESSMENT_ONEDRIVE_DRIVE_ID', ''),
        'root_folder' => rtrim((string) env('ONEDRIVE_ROOT_FOLDER', '/Assessments'), '/'),
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
