<?php
/**
 * Site root entry point. Ensures the visitor is authenticated via
 * auth_handler.php (Microsoft Entra ID SSO) and then hands off to the
 * assessment platform subfolder. Other site sections can perform the same
 * check and redirect into their own subfolder.
 */

declare(strict_types=1);

require_once __DIR__ . '/assessment/config/config.php';

session_name(config('session.name'));
session_start();

if (empty($_SESSION['user'])) {
    header('Location: /auth_handler.php?action=login&return_to=' . urlencode('/assessment/'));
    exit;
}

header('Location: /assessment/');
exit;
