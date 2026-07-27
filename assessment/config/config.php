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

// includes/secrets.php lives at the SITE root (alongside index.php/
// auth_handler.php), one level above the assessment app's own folder -
// i.e. two levels up from this file (assessment/config/config.php).
require_once dirname(__DIR__, 2) . '/includes/secrets.php';

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

    // AES-256-GCM key used for every piece of encrypted-at-rest student
    // content: mark schemes/model answers, typed answers, in-PDF
    // annotations, self-mark reflections, marker comments (see
    // models/Crypto.php). Must be set in .env.php before first use - once
    // data is encrypted with it, changing it makes that data unreadable.
    'encryption_key' => env('ASSESSMENT_ENCRYPTION_KEY'),

    // Only used by the one-off migrate_encrypt.php script (see
    // config/migrate_encrypt.sql) - a shared secret so that script can't be
    // triggered by a random visitor. Not needed for normal operation.
    'migration_secret' => env('ASSESSMENT_MIGRATION_SECRET', ''),

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

    // A OneDrive/SharePoint folder shared once, manually, with "People in
    // the organisation with the link can edit" - the same sharing action
    // any staff member can do from the OneDrive/SharePoint UI, no Azure
    // Portal or admin consent needed. See services/OneDriveService.php.
    'onedrive' => [
        'master_folder_link' => env('ASSESSMENT_ONEDRIVE_FOLDER_LINK', ''),
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
