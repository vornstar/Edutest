<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/PaperController.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/TestAssignment.php';
require_once __DIR__ . '/../models/Paper.php';
require_once __DIR__ . '/../models/ClassRoster.php';
require_once __DIR__ . '/../models/Submission.php';
require_once __DIR__ . '/../services/ImagesToPdf.php';
require_once __DIR__ . '/../services/OneDriveService.php';

/**
 * Mobile-friendly workflow for a teacher who set a physical paper copy of a
 * test: they photograph each student's pages here, the photos are combined
 * into a single PDF (see ImagesToPdf), and it's attached to that student's
 * submission exactly as if the student had uploaded a scan themselves - see
 * TestController::uploadScan() / Submission::attachScan() - so it flows
 * into the same marking/moderation/annotation pipeline either way.
 */
final class ScanUploadController
{
    /** @return array{0: array, 1: array} [assignment, paper] */
    private static function authorizeAssignment(int $assignmentId, array $user): array
    {
        $assignment = TestAssignment::find($assignmentId);
        if (!$assignment || $assignment['class_id'] === null) {
            http_response_code(404);
            exit;
        }
        $paper = PaperController::requireManageable((int) $assignment['paper_id'], $user);
        return [$assignment, $paper];
    }

    public static function form(int $assignmentId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        [$assignment, $paper] = self::authorizeAssignment($assignmentId, $user);
        $students = ClassRoster::students((int) $assignment['class_id']);
        require __DIR__ . '/../views/teacher/upload_scan.php';
    }

    public static function store(int $assignmentId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();
        [$assignment, $paper] = self::authorizeAssignment($assignmentId, $user);

        $studentId = (int) ($_POST['student_id'] ?? 0);
        if (!ClassRoster::isMember((int) $assignment['class_id'], $studentId)) {
            http_response_code(422);
            echo 'That student is not in this class.';
            exit;
        }

        $photos = self::collectUploadedPhotos();
        if (!$photos) {
            header('Location: /assessment/teacher/assignments/' . $assignmentId . '/upload-scan?student_id=' . $studentId . '&error=no_photos');
            exit;
        }

        try {
            $pdfBinary = ImagesToPdf::build($photos);
        } catch (Throwable $e) {
            http_response_code(422);
            echo 'Could not process those photos: ' . htmlspecialchars($e->getMessage());
            exit;
        }

        $submission = Submission::startOrGet($assignmentId, $studentId);
        $drive = new OneDriveService((int) $user['id']);
        $driveItemId = $drive->uploadScannedScript((int) $paper['id'], $studentId, $pdfBinary, 'pdf');
        Submission::attachScan((int) $submission['id'], $driveItemId);

        header('Location: /assessment/teacher/assignments/' . $assignmentId . '/upload-scan?student_id=' . $studentId . '&uploaded=1');
        exit;
    }

    /** @return array<int,string> raw binary of each uploaded photo, in submitted order */
    private static function collectUploadedPhotos(): array
    {
        $field = $_FILES['photos'] ?? null;
        if (!$field || !is_array($field['tmp_name'])) {
            return [];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $photos = [];
        foreach ($field['tmp_name'] as $i => $tmpName) {
            if (($field['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($tmpName)) {
                continue;
            }
            $mime = finfo_file($finfo, $tmpName);
            if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
                continue;
            }
            $photos[] = (string) file_get_contents($tmpName);
        }
        finfo_close($finfo);
        return $photos;
    }
}
