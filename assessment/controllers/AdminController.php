<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/AuditLog.php';
require_once __DIR__ . '/../services/OneDriveService.php';

/**
 * Full system access: global role management and system audit review (SRS 3.2, Admin row).
 */
final class AdminController
{
    public static function users(): void
    {
        AuthController::requireRole([User::ROLE_ADMIN]);
        $users = User::all();
        require __DIR__ . '/../views/admin/users_index.php';
    }

    public static function setRole(int $userId): void
    {
        $admin = AuthController::requireRole([User::ROLE_ADMIN]);
        AuthController::verifyCsrf();

        $role = (string) ($_POST['role'] ?? '');
        User::setRole($userId, $role, (int) $admin['id']);

        header('Location: /assessment/admin/users');
        exit;
    }

    /** Sets which subject (matched against papers.subject) a Subject Leader has department-wide authority over. */
    public static function setManagedSubject(int $userId): void
    {
        $admin = AuthController::requireRole([User::ROLE_ADMIN]);
        AuthController::verifyCsrf();

        User::setManagedSubject($userId, (string) ($_POST['managed_subject'] ?? ''), (int) $admin['id']);

        header('Location: /assessment/admin/users');
        exit;
    }

    /** Pre-provisions a colleague from the same tenant by email with a chosen role, before their first sign-in. */
    public static function addUser(): void
    {
        $admin = AuthController::requireRole([User::ROLE_ADMIN]);
        AuthController::verifyCsrf();

        $email = trim((string) ($_POST['email'] ?? ''));
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $role = (string) ($_POST['role'] ?? User::ROLE_STUDENT);

        if ($email !== '') {
            User::addByEmail($email, $displayName, $role, (int) $admin['id']);
        }

        header('Location: /assessment/admin/users');
        exit;
    }

    /**
     * Admin > OneDrive setup: no Graph lookup is possible here, since a
     * shared-link-based folder (see OneDriveService) is created by hand in
     * the OneDrive/SharePoint UI, not discovered via the API. This just
     * tests whatever link is currently in ASSESSMENT_ONEDRIVE_FOLDER_LINK
     * and reports whether it resolves, so setup can be confirmed without
     * having to attempt a real file upload first.
     */
    public static function oneDriveLookup(): void
    {
        $admin = AuthController::requireRole([User::ROLE_ADMIN]);

        $configuredLink = (string) config('onedrive.master_folder_link');
        $testResult = null;
        $testError = null;

        if ($configuredLink !== '') {
            try {
                $drive = new OneDriveService((int) $admin['id']);
                $testResult = $drive->testMasterFolder();
            } catch (Throwable $e) {
                $testError = $e->getMessage();
            }
        }

        require __DIR__ . '/../views/admin/onedrive_lookup.php';
    }

    public static function auditLog(): void
    {
        AuthController::requireRole([User::ROLE_ADMIN]);
        $entityType = $_GET['entity_type'] ?? '';
        $entityId = (int) ($_GET['entity_id'] ?? 0);
        $entries = ($entityType && $entityId) ? AuditLog::forEntity($entityType, $entityId) : [];
        require __DIR__ . '/../views/admin/audit_log.php';
    }
}
