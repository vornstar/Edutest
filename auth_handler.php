<?php
/*
    Filename: auth_handler.php
    Description: Central authentication handler for Microsoft OAuth.
                 Handles redirection to mobile/desktop dashboards.
                 Logs user access parameters to u781387176_core.user_logins without IP logging.
                 Maintains dual-database synchronization for side-by-side support.
*/

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' cdn.tailwindcss.com cdnjs.cloudflare.com cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' cdnjs.cloudflare.com; font-src 'self' cdnjs.cloudflare.com; img-src 'self' data:;");
header('Content-Type: text/html; charset=utf-8');

// Include root db.php to connect to the legacy database ($conn)
include 'db.php';

require_once __DIR__ . '/includes/encryption.php';
require_once __DIR__ . '/includes/secrets.php';

// Detect mobile source: 'state=mobile' from MS OAuth or 'source=mobile' from POST
$is_mobile = (isset($_GET['state']) && $_GET['state'] === 'mobile') ||
             (isset($_POST['source']) && $_POST['source'] === 'mobile');

$clientId     = "eb393a58-2841-4188-9e8e-0dd26026b2e6";
$tenantId     = "3df55413-ced7-4b48-8f6e-30bc4dac254f";
$clientSecret = qmhs_env('AZURE_CLIENT_SECRET'); // see .env.php / .env.php.example - kept out of source control
$redirectUri  = "https://www.qmhsportal.co.uk/auth_handler.php";

$scopes = "openid profile email offline_access EduRoster.ReadBasic EduAssignments.ReadWrite Calendars.ReadWrite Tasks.Read Tasks.ReadWrite";

// --- CASE 1: MICROSOFT REDIRECT ---
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    $tokenUrl = "https://login.microsoftonline.com/$tenantId/oauth2/v2.0/token";

    $postData = [
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'code'          => $code,
        'redirect_uri'  => $redirectUri,
        'grant_type'          => 'authorization_code',
        'scope'         => $scopes
    ];

    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($response['access_token'])) {
        $_SESSION['access_token'] = $response['access_token'];

        // Save the refresh token and expiry
        if (isset($response['refresh_token'])) {
            $_SESSION['refresh_token'] = $response['refresh_token'];
        }
        if (isset($response['expires_in'])) {
            $_SESSION['token_expires_at'] = time() + $response['expires_in'] - 300; // 5 minute buffer
        }

        $ch = curl_init("https://graph.microsoft.com/v1.0/me");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $response['access_token']]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $userData = json_decode(curl_exec($ch), true);
        curl_close($ch);

        $email = strtolower(trim($userData['mail'] ?? $userData['userPrincipalName'] ?? ''));

        // 1. Establish connection to the new core database
        $coreConn = new mysqli('localhost', 'u781387176_core_admin', qmhs_env('CORE_DB_PASSWORD'), 'u781387176_core');
        if ($coreConn->connect_error) {
            die("Identity verification service offline.");
        }
        $coreConn->set_charset('utf8mb4');

        $emailHash = hash('sha256', $email);

        // Query the new core database
        $stmtCore = $coreConn->prepare("SELECT id, role, full_name FROM users WHERE email_hash = ? LIMIT 1");
        $stmtCore->bind_param("s", $emailHash);
        $stmtCore->execute();
        $userCore = $stmtCore->get_result()->fetch_assoc();

        // Query the legacy root database ($conn)
        $stmtLegacy = $conn->prepare("SELECT id, role, full_name FROM users WHERE LOWER(email) = ? LIMIT 1");
        $stmtLegacy->bind_param("s", $email);
        $stmtLegacy->execute();
        $userLegacy = $stmtLegacy->get_result()->fetch_assoc();

        $fullName = $userData['displayName'] ?? ucwords(str_replace('.', ' ', explode('@', $email)[0]));
        $defaultRole = 'student';

        // Core Database: Auto-provision if missing
        if (!$userCore && !empty($email)) {
            $encName = Encryption::encrypt($fullName);
            $encEmail = Encryption::encrypt($email);

            // If user already exists in legacy database, keep the same ID to prevent divergence
            if ($userLegacy) {
                $insertCore = $coreConn->prepare("INSERT INTO users (id, full_name, email, email_hash, role) VALUES (?, ?, ?, ?, ?)");
                $insertCore->bind_param("issss", $userLegacy['id'], $encName, $encEmail, $emailHash, $userLegacy['role']);
            } else {
                $insertCore = $coreConn->prepare("INSERT INTO users (full_name, email, email_hash, role) VALUES (?, ?, ?, ?)");
                $insertCore->bind_param("ssss", $encName, $encEmail, $emailHash, $defaultRole);
            }

            if ($insertCore->execute()) {
                $newId = $coreConn->insert_id;
                $userCore = [
                    'id' => $newId,
                    'role' => $userLegacy ? $userLegacy['role'] : $defaultRole,
                    'full_name' => $fullName
                ];
            }
        } else if ($userCore) {
            $userCore['full_name'] = Encryption::decrypt($userCore['full_name']);
        }

        // Legacy Database: Auto-provision if missing
        if (!$userLegacy && !empty($email)) {
            $passwordPlaceholder = '';

            // If user was created in Core, match the ID
            if ($userCore) {
                $insertLegacy = $conn->prepare("INSERT INTO users (id, email, role, full_name, password_hash) VALUES (?, ?, ?, ?, ?)");
                $insertLegacy->bind_param("issss", $userCore['id'], $email, $userCore['role'], $fullName, $passwordPlaceholder);
            } else {
                $insertLegacy = $conn->prepare("INSERT INTO users (email, role, full_name, password_hash) VALUES (?, ?, ?, ?)");
                $insertLegacy->bind_param("ssss", $email, $defaultRole, $fullName, $passwordPlaceholder);
            }

            if ($insertLegacy->execute()) {
                $newId = $conn->insert_id;
                $userLegacy = [
                    'id' => $newId,
                    'role' => $userCore ? $userCore['role'] : $defaultRole,
                    'full_name' => $fullName
                ];
            }
        }

        $activeUser = $userCore ?: $userLegacy;

        if ($activeUser) {
            $_SESSION['user_id'] = (int)$activeUser['id'];
            $_SESSION['role'] = strtolower($activeUser['role']);
            $_SESSION['user_name'] = $activeUser['full_name'];
            $_SESSION['user_email'] = $email;

            // Track successful login inside core.user_logins without logging the IP address
            $logStmt = $coreConn->prepare("INSERT INTO user_logins (user_id, user_agent) VALUES (?, ?)");
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $logStmt->bind_param("is", $_SESSION['user_id'], $ua);
            $logStmt->execute();

            $coreConn->close();
            handle_redirect($is_mobile, $_SESSION['role']);
        } else {
            $coreConn->close();
        }
    }
}

function handle_redirect($is_mobile, $role) {
    if (!empty($_SESSION['redirect_to'])) {
        $target = filter_var($_SESSION['redirect_to'], FILTER_SANITIZE_URL);
        unset($_SESSION['redirect_to']);
    } else {
        if (strpos($role, 'student') !== false) {
            $target = $is_mobile ? "mobile/mobile_home.php" : "student_view.php?p=8ab44051";
        } else {
            $target = "staff_landing.php";
        }
    }
    header("Location: $target");
    exit();
}

// Helper function to refresh token silently
function refreshMicrosoftToken() {
    if (empty($_SESSION['refresh_token'])) {
        return false;
    }

    $clientId     = "eb393a58-2841-4188-9e8e-0dd26026b2e6";
    $tenantId     = "3df55413-ced7-4b48-8f6e-30bc4dac254f";
    $clientSecret = qmhs_env('AZURE_CLIENT_SECRET');

    $url = "https://login.microsoftonline.com/$tenantId/oauth2/v2.0/token";

    $postData = http_build_query([
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $_SESSION['refresh_token'],
        'grant_type'    => 'refresh_token'
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    $tokenData = json_decode($response, true);

    if (isset($tokenData['access_token'])) {
        $_SESSION['access_token'] = $tokenData['access_token'];
        if (isset($tokenData['refresh_token'])) {
            $_SESSION['refresh_token'] = $tokenData['refresh_token'];
        }
        if (isset($tokenData['expires_in'])) {
            $_SESSION['token_expires_at'] = time() + $tokenData['expires_in'] - 300;
        }
        return true;
    }

    return false;
}

// Guard to prevent redirect if this file is included elsewhere
if (basename($_SERVER['PHP_SELF']) === 'auth_handler.php') {
    header("Location: index.php?error=invalid");
    exit();
}
