<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../models/Paper.php';
require_once __DIR__ . '/../models/Submission.php';
require_once __DIR__ . '/../models/TestAssignment.php';
require_once __DIR__ . '/../models/ClassRoster.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Branding.php';
require_once __DIR__ . '/../services/OneDriveService.php';

/**
 * All PDF/scan bytes flow through here so OneDrive item ids and share links
 * are never exposed to the browser (SRS 5.2). Every route re-checks
 * authorization before streaming a single byte.
 */
final class FileProxyController
{
    public static function paperPdf(int $paperId, string $kind): void
    {
        $user = AuthController::requireLogin();
        $paper = Paper::find($paperId);
        if (!$paper) {
            http_response_code(404);
            exit;
        }

        $role = $user['role'];
        $isOwnerTeacher = in_array($role, [User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER, User::ROLE_ADMIN], true);

        if ($kind === 'markscheme') {
            // Mark schemes are only ever released to students via the explicit
            // self-marking workflow (assignment.self_marking_enabled), never as a raw file fetch.
            if (!$isOwnerTeacher) {
                http_response_code(403);
                exit;
            }
            $itemId = $paper['mark_scheme_drive_item_id'];
        } else {
            if (!$isOwnerTeacher && $role !== User::ROLE_STUDENT) {
                http_response_code(403);
                exit;
            }
            $itemId = $paper['pdf_drive_item_id'];
        }

        if (!$itemId) {
            http_response_code(404);
            exit;
        }

        self::stream((int) $user['id'], (string) $itemId, 'paper.pdf');
    }

    /**
     * The pdf-type mark scheme PDF for a student's OWN submission -
     * separate from paperPdf()'s teacher-only markscheme kind, since
     * release here depends on THIS submission's assignment
     * (self_marking_enabled + submitted/self_marked status), not just
     * being staff - self_marking_enabled is per-assignment, not per-paper,
     * so a plain paper-id-keyed route wouldn't have enough context to gate
     * it correctly. Mirrors the exact check TestController::selfMarkForm()
     * already applies before even rendering the self-marking screen.
     */
    public static function selfMarkScheme(int $submissionId): void
    {
        $user = AuthController::requireRole([User::ROLE_STUDENT]);
        $submission = Submission::find($submissionId);
        if (!$submission || (int) $submission['student_id'] !== (int) $user['id']) {
            http_response_code(404);
            exit;
        }

        $assignment = TestAssignment::find((int) $submission['assignment_id']);
        if (!$assignment || empty($assignment['self_marking_enabled']) || !in_array($submission['status'], ['submitted', 'self_marked'], true)) {
            http_response_code(403);
            exit;
        }

        $paper = Paper::find((int) $assignment['paper_id']);
        if (!$paper || !$paper['mark_scheme_drive_item_id']) {
            http_response_code(404);
            exit;
        }

        self::stream((int) $user['id'], (string) $paper['mark_scheme_drive_item_id'], 'mark_scheme.pdf');
    }

    public static function scannedScript(int $submissionId): void
    {
        $user = AuthController::requireLogin();
        $submission = Submission::find($submissionId);
        if (!$submission || !$submission['scan_drive_item_id']) {
            http_response_code(404);
            exit;
        }

        $isOwner = (int) $submission['student_id'] === (int) $user['id'];
        $isStaff = in_array($user['role'], [User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER, User::ROLE_ADMIN], true);

        if (!$isOwner && !$isStaff) {
            http_response_code(403);
            exit;
        }

        self::stream((int) $user['id'], (string) $submission['scan_drive_item_id'], 'script.pdf');
    }

    /**
     * The school's logo (see Admin > Branding / OneDriveService::
     * uploadBrandingLogo) - shown in the header on every page, so unlike
     * every other file here this is cached aggressively (a school's logo
     * essentially never changes) rather than no-store, and any signed-in
     * user can fetch it, not just staff - it's not student data.
     */
    public static function brandingLogo(): void
    {
        $user = AuthController::requireLogin();
        $branding = Branding::get();
        if (!$branding['logo_drive_item_id']) {
            http_response_code(404);
            exit;
        }

        self::stream(
            (int) $user['id'],
            (string) $branding['logo_drive_item_id'],
            'logo',
            (string) ($branding['logo_content_type'] ?? 'application/octet-stream'),
            'private, max-age=86400'
        );
    }

    private static function stream(int $actingUserId, string $driveItemId, string $downloadName, string $contentType = 'application/pdf', string $cacheControl = 'private, max-age=0, no-store'): void
    {
        $drive = new OneDriveService($actingUserId);
        try {
            $bytes = $drive->downloadById($driveItemId);
        } catch (Throwable $e) {
            http_response_code(502);
            echo 'Unable to retrieve file from OneDrive.';
            return;
        }

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: inline; filename="' . $downloadName . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: ' . $cacheControl);
        echo $bytes;
    }
}
