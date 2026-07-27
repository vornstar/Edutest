<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../models/Paper.php';
require_once __DIR__ . '/../models/Submission.php';
require_once __DIR__ . '/../models/TestAssignment.php';
require_once __DIR__ . '/../models/ClassRoster.php';
require_once __DIR__ . '/../models/User.php';
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
            // self-marking workflow (paper.self_marking_enabled), never as a raw file fetch.
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

        self::stream((string) $itemId, 'paper.pdf');
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

        self::stream((string) $submission['scan_drive_item_id'], 'script.pdf');
    }

    private static function stream(string $driveItemId, string $downloadName): void
    {
        $drive = new OneDriveService();
        try {
            $bytes = $drive->downloadById($driveItemId);
        } catch (Throwable $e) {
            http_response_code(502);
            echo 'Unable to retrieve file from OneDrive.';
            return;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $downloadName . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, no-store');
        echo $bytes;
    }
}
