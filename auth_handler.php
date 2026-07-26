<?php
/**
 * Site-wide Microsoft Entra ID (Azure AD) OAuth 2.0 authorization-code handler.
 *
 * This file lives at the web root and is shared by every subfolder
 * application on this site (including /assessment/). It performs the
 * SSO handshake, provisions/updates the local user record, stores the
 * role-agnostic identity in the session, and returns the browser to
 * whichever application initiated the login via the `return_to` parameter.
 *
 * Actions:
 *   auth_handler.php?action=login&return_to=/assessment/
 *   auth_handler.php (no params)  -> OAuth redirect target, handles ?code=...
 *   auth_handler.php?action=logout
 */

declare(strict_types=1);

require_once __DIR__ . '/assessment/config/config.php';
require_once __DIR__ . '/assessment/models/Database.php';
require_once __DIR__ . '/assessment/models/Crypto.php';

session_name(config('session.name'));
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

const OAUTH_AUTHORITY = 'https://login.microsoftonline.com';

function auth_redirect_uri(): string
{
    $configured = (string) config('azure.redirect_uri', '');
    if ($configured !== '') {
        return $configured;
    }
    $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . '/auth_handler.php';
}

function auth_safe_return_to(?string $path): string
{
    // Only allow same-site relative paths to prevent open-redirect abuse.
    if ($path === null || $path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
        return '/assessment/';
    }
    return $path;
}

$action = $_GET['action'] ?? null;

if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: /');
    exit;
}

if ($action === 'login') {
    $returnTo = auth_safe_return_to($_GET['return_to'] ?? '/assessment/');
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_return_to'] = $returnTo;

    $tenant = config('azure.tenant_id');
    $params = http_build_query([
        'client_id' => config('azure.client_id'),
        'response_type' => 'code',
        'redirect_uri' => auth_redirect_uri(),
        'response_mode' => 'query',
        'scope' => 'openid profile email ' . config('graph.scopes'),
        'state' => $state,
    ]);

    header('Location: ' . OAUTH_AUTHORITY . "/{$tenant}/oauth2/v2.0/authorize?{$params}");
    exit;
}

// --- OAuth callback (Microsoft redirects here with ?code=&state=) ---
if (isset($_GET['code'])) {
    $state = $_GET['state'] ?? '';
    if (!hash_equals($_SESSION['oauth_state'] ?? '', $state)) {
        http_response_code(400);
        echo 'Invalid OAuth state.';
        exit;
    }
    unset($_SESSION['oauth_state']);

    $tenant = config('azure.tenant_id');
    $tokenResponse = auth_post_form(OAUTH_AUTHORITY . "/{$tenant}/oauth2/v2.0/token", [
        'client_id' => config('azure.client_id'),
        'client_secret' => config('azure.client_secret'),
        'grant_type' => 'authorization_code',
        'code' => $_GET['code'],
        'redirect_uri' => auth_redirect_uri(),
        'scope' => 'openid profile email ' . config('graph.scopes'),
    ]);

    if (!isset($tokenResponse['access_token'], $tokenResponse['id_token'])) {
        http_response_code(502);
        echo 'Authentication failed while contacting Microsoft Entra ID.';
        exit;
    }

    $claims = auth_decode_id_token_claims($tokenResponse['id_token']);

    $tenantId = (string) ($claims['tid'] ?? $tenant);
    $azureUserId = (string) ($claims['oid'] ?? $claims['sub'] ?? '');
    $email = (string) ($claims['preferred_username'] ?? $claims['email'] ?? '');
    $displayName = (string) ($claims['name'] ?? $email);

    if ($azureUserId === '' || $email === '') {
        http_response_code(502);
        echo 'Identity token did not contain the expected claims.';
        exit;
    }

    $user = auth_provision_user($tenantId, $azureUserId, $email, $displayName);
    auth_store_graph_tokens((int) $user['id'], $tokenResponse);

    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'tenant_id' => $tenantId,
        'azure_user_id' => $azureUserId,
        'email' => $email,
        'display_name' => $displayName,
        'role_id' => (int) $user['role_id'],
    ];
    $_SESSION['login_at'] = time();

    $returnTo = auth_safe_return_to($_SESSION['oauth_return_to'] ?? '/assessment/');
    unset($_SESSION['oauth_return_to']);

    header('Location: ' . $returnTo);
    exit;
}

// No recognized action - send the user to start the login flow.
header('Location: /auth_handler.php?action=login&return_to=' . urlencode(auth_safe_return_to($_GET['return_to'] ?? '/assessment/')));
exit;

/** @return array<string,mixed> */
function auth_post_form(string $url, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        error_log('auth_handler token request failed: ' . $err);
        return [];
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : [];
}

/** @return array<string,mixed> */
function auth_decode_id_token_claims(string $idToken): array
{
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) {
        return [];
    }
    $payload = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);
    $claims = json_decode((string) $payload, true);
    return is_array($claims) ? $claims : [];
}

/** @return array<string,mixed> */
function auth_provision_user(string $tenantId, string $azureUserId, string $email, string $displayName): array
{
    $pdo = Database::connection();

    $stmt = $pdo->prepare('SELECT * FROM users WHERE tenant_id = :tenant_id AND azure_user_id = :azure_user_id');
    $stmt->execute(['tenant_id' => $tenantId, 'azure_user_id' => $azureUserId]);
    $existing = $stmt->fetch();

    if ($existing) {
        $update = $pdo->prepare('UPDATE users SET email = :email, display_name = :display_name WHERE id = :id');
        $update->execute(['email' => $email, 'display_name' => $displayName, 'id' => $existing['id']]);
        $existing['email'] = $email;
        $existing['display_name'] = $displayName;
        return $existing;
    }

    // New identities default to the "student" role; an Admin promotes staff
    // accounts via the Admin portal's role management screen.
    $insert = $pdo->prepare(
        'INSERT INTO users (tenant_id, azure_user_id, email, display_name, role_id)
         VALUES (:tenant_id, :azure_user_id, :email, :display_name, 1)'
    );
    $insert->execute([
        'tenant_id' => $tenantId,
        'azure_user_id' => $azureUserId,
        'email' => $email,
        'display_name' => $displayName,
    ]);

    $stmt->execute(['tenant_id' => $tenantId, 'azure_user_id' => $azureUserId]);
    return $stmt->fetch();
}

function auth_store_graph_tokens(int $userId, array $tokenResponse): void
{
    $pdo = Database::connection();
    $expiresAt = (new DateTimeImmutable())->modify('+' . (int) ($tokenResponse['expires_in'] ?? 3600) . ' seconds');

    $stmt = $pdo->prepare(
        'INSERT INTO graph_tokens (user_id, access_token, refresh_token, expires_at)
         VALUES (:user_id, :access_token, :refresh_token, :expires_at)
         ON DUPLICATE KEY UPDATE access_token = VALUES(access_token),
             refresh_token = VALUES(refresh_token), expires_at = VALUES(expires_at)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'access_token' => Crypto::encrypt($tokenResponse['access_token']),
        'refresh_token' => isset($tokenResponse['refresh_token']) ? Crypto::encrypt($tokenResponse['refresh_token']) : null,
        'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
    ]);
}
