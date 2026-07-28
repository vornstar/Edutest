<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/AuditLog.php';
require_once __DIR__ . '/../models/Subject.php';
require_once __DIR__ . '/../models/Branding.php';
require_once __DIR__ . '/../models/DataProtection.php';
require_once __DIR__ . '/../services/OneDriveService.php';

/**
 * Full system access: global role management and system audit review (SRS 3.2, Admin row).
 */
final class AdminController
{
    public static function users(): void
    {
        AuthController::requireRole([User::ROLE_ADMIN]);
        $query = trim((string) ($_GET['q'] ?? ''));

        // display_name is encrypted at rest (see User::hydrate), so this can't be a SQL WHERE/LIKE -
        // fetch (a generously bounded, school-scale) full list, already decrypted, and filter in PHP.
        $users = User::all(5000);
        if ($query !== '') {
            $needle = mb_strtolower($query);
            $users = array_values(array_filter(
                $users,
                static fn(array $u): bool => str_contains(mb_strtolower($u['display_name']), $needle)
            ));
        }

        $subjects = Subject::all();
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

    /**
     * The canonical subject list backing every subject dropdown (teacher
     * assignment in Admin > Users, paper creation) - see Subject model /
     * schema.sql for why this is a plain reference list, not a foreign key.
     */
    public static function subjects(): void
    {
        AuthController::requireRole([User::ROLE_ADMIN]);
        $subjects = Subject::all();
        require __DIR__ . '/../views/admin/subjects_index.php';
    }

    public static function addSubject(): void
    {
        AuthController::requireRole([User::ROLE_ADMIN]);
        AuthController::verifyCsrf();

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '') {
            try {
                Subject::create($name);
            } catch (PDOException $e) {
                // Duplicate name (uq_subject_name) - it already exists, nothing to do.
            }
        }

        header('Location: /assessment/admin/subjects');
        exit;
    }

    public static function renameSubject(int $subjectId): void
    {
        AuthController::requireRole([User::ROLE_ADMIN]);
        AuthController::verifyCsrf();

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '') {
            try {
                Subject::rename($subjectId, $name);
            } catch (PDOException $e) {
                // Duplicate name (uq_subject_name) - leave the existing entry as it was.
            }
        }

        header('Location: /assessment/admin/subjects');
        exit;
    }

    public static function deleteSubject(int $subjectId): void
    {
        AuthController::requireRole([User::ROLE_ADMIN]);
        AuthController::verifyCsrf();

        Subject::delete($subjectId);

        header('Location: /assessment/admin/subjects');
        exit;
    }

    /** Only these image types are accepted for a logo upload - deliberately excludes SVG (script-content risk) even though it'd otherwise be a natural fit for a crisp logo. */
    private const ALLOWED_LOGO_TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public static function branding(): void
    {
        AuthController::requireRole([User::ROLE_ADMIN]);
        $branding = Branding::get();
        require __DIR__ . '/../views/admin/branding.php';
    }

    /** School name, brand colours, and (optionally) a logo - see Branding model and views/admin/branding.php. */
    public static function updateBranding(): void
    {
        $user = AuthController::requireRole([User::ROLE_ADMIN]);
        AuthController::verifyCsrf();

        $current = Branding::get();
        $schoolName = trim((string) ($_POST['school_name'] ?? '')) ?: 'Assessment Platform';
        // <input type="color"> always submits SOME value, so there's no way to tell "left it
        // alone" apart from "chose this specific colour" - a checkbox is the only clean way to
        // let a school explicitly go back to the platform default rather than just picking colours.
        $primaryColor = !empty($_POST['reset_primary_color']) ? null : self::normalizeHexColor((string) ($_POST['primary_color'] ?? ''));
        $accentColor = !empty($_POST['reset_accent_color']) ? null : self::normalizeHexColor((string) ($_POST['accent_color'] ?? ''));
        $studentWorkColor = !empty($_POST['reset_student_work_color']) ? null : self::normalizeHexColor((string) ($_POST['student_work_color'] ?? ''));
        $teacherMarkingColor = !empty($_POST['reset_teacher_marking_color']) ? null : self::normalizeHexColor((string) ($_POST['teacher_marking_color'] ?? ''));
        $teacherModerationColor = !empty($_POST['reset_teacher_moderation_color']) ? null : self::normalizeHexColor((string) ($_POST['teacher_moderation_color'] ?? ''));
        $selfMarkingColor = !empty($_POST['reset_self_marking_color']) ? null : self::normalizeHexColor((string) ($_POST['self_marking_color'] ?? ''));

        $logoDriveItemId = $current['logo_drive_item_id'];
        $logoContentType = $current['logo_content_type'];
        if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
            [$logoDriveItemId, $logoContentType] = self::storeLogoUpload($_FILES['logo'], (int) $user['id']);
        } elseif (!empty($_POST['remove_logo'])) {
            // The old OneDrive file is left in place (same as replacing a paper's PDF elsewhere in
            // this app never deletes the previous version) - only the reference is cleared.
            $logoDriveItemId = null;
            $logoContentType = null;
        }

        Branding::save(
            $schoolName,
            $logoDriveItemId,
            $logoContentType,
            $primaryColor,
            $accentColor,
            $studentWorkColor,
            $teacherMarkingColor,
            $teacherModerationColor,
            $selfMarkingColor
        );

        header('Location: /assessment/admin/branding');
        exit;
    }

    /** @return array{0:string,1:string} [driveItemId, contentType] */
    private static function storeLogoUpload(array $file, int $actingUserId): array
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $ext = self::ALLOWED_LOGO_TYPES[$mime] ?? null;
        if ($ext === null) {
            http_response_code(422);
            echo 'Logo must be a PNG, JPEG, or WebP image.';
            exit;
        }

        $content = file_get_contents($file['tmp_name']);
        $drive = new OneDriveService($actingUserId);
        $itemId = $drive->uploadBrandingLogo('logo.' . $ext, $content, $mime);

        return [$itemId, $mime];
    }

    private static function normalizeHexColor(string $value): ?string
    {
        $value = trim($value);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : null;
    }

    /**
     * Admin > Data protection: a subject access export for one user, a way
     * to anonymize one (erasure), and a way to bulk-delete submissions
     * older than a chosen date (storage limitation). See DataProtection
     * for what each of these actually does and why - this controller is
     * just the search/preview/confirmation UI in front of it.
     */
    public static function dataProtection(): void
    {
        AuthController::requireRole([User::ROLE_ADMIN]);

        $query = trim((string) ($_GET['q'] ?? ''));
        $users = [];
        if ($query !== '') {
            $needle = mb_strtolower($query);
            $users = array_values(array_filter(
                User::all(5000),
                static fn(array $u): bool => str_contains(mb_strtolower($u['display_name']), $needle) || str_contains(mb_strtolower($u['email']), $needle)
            ));
        }

        $previewCutoff = trim((string) ($_GET['preview_before'] ?? ''));
        $retentionPreview = null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $previewCutoff)) {
            $retentionPreview = DataProtection::submissionsOlderThan($previewCutoff);
        }

        require __DIR__ . '/../views/admin/data_protection.php';
    }

    /** Downloads one user's full subject access export as JSON - see DataProtection::exportUser. */
    public static function exportUserData(int $userId): void
    {
        AuthController::requireRole([User::ROLE_ADMIN]);

        try {
            $export = DataProtection::exportUser($userId);
        } catch (InvalidArgumentException $e) {
            http_response_code(404);
            exit;
        }

        $filename = 'user-' . $userId . '-data-export-' . date('Y-m-d') . '.json';
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Anonymizes one user (see DataProtection::anonymizeUser) - gated by typing their current display name exactly, since this can't be undone. */
    public static function anonymizeUser(int $userId): void
    {
        $admin = AuthController::requireRole([User::ROLE_ADMIN]);
        AuthController::verifyCsrf();

        $user = User::find($userId);
        if (!$user) {
            http_response_code(404);
            exit;
        }

        $typed = trim((string) ($_POST['confirm_name'] ?? ''));
        if ($typed === '' || $typed !== $user['display_name']) {
            http_response_code(422);
            echo 'Confirmation text did not match this user\'s current display name exactly - nothing was changed.';
            exit;
        }

        DataProtection::anonymizeUser($userId, (int) $admin['id']);

        header('Location: /assessment/admin/data-protection?anonymized=' . $userId);
        exit;
    }

    /** Bulk-deletes submissions older than a chosen date (see DataProtection::deleteSubmissionsOlderThan) - gated by typing DELETE exactly, since this is genuinely irreversible, unlike everything else this app deletes. */
    public static function deleteOldData(): void
    {
        $admin = AuthController::requireRole([User::ROLE_ADMIN]);
        AuthController::verifyCsrf();

        $cutoffDate = trim((string) ($_POST['cutoff_date'] ?? ''));
        $confirmText = trim((string) ($_POST['confirm_text'] ?? ''));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoffDate) || strtoupper($confirmText) !== 'DELETE') {
            http_response_code(422);
            echo 'Confirmation failed - a valid cutoff date and typing DELETE exactly are both required. Nothing was changed.';
            exit;
        }

        $deleted = DataProtection::deleteSubmissionsOlderThan($cutoffDate, (int) $admin['id'], (int) $admin['id']);

        header('Location: /assessment/admin/data-protection?deleted=' . $deleted);
        exit;
    }
}
