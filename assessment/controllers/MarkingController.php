<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../models/Submission.php';
require_once __DIR__ . '/../models/TestAssignment.php';
require_once __DIR__ . '/../models/Paper.php';
require_once __DIR__ . '/../models/Question.php';
require_once __DIR__ . '/../models/Mark.php';
require_once __DIR__ . '/../models/Annotation.php';
require_once __DIR__ . '/../models/User.php';

/**
 * Teacher on-screen marking (SRS 7.1) and canvas annotation persistence
 * (SRS 7.2). The mark scheme is decrypted here, server-side, purely for
 * display to an authorized marker - never sent anywhere else in cleartext.
 */
final class MarkingController
{
    public static function queue(): void
    {
        $user = AuthController::requireRole([User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER]);
        $papers = Paper::byCreator((int) $user['id']);
        require __DIR__ . '/../views/teacher/marking_queue.php';
    }

    public static function markSubmission(int $submissionId): void
    {
        $user = AuthController::requireRole([User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER]);

        $submission = Submission::find($submissionId);
        if (!$submission) {
            http_response_code(404);
            exit;
        }
        $assignment = TestAssignment::find((int) $submission['assignment_id']);
        $paper = Paper::find((int) $assignment['paper_id']);
        $questions = Question::forPaper((int) $paper['id']);

        $answers = Submission::answers($submissionId);
        $selfMarks = Submission::selfMarks($submissionId);
        $primaryMarks = Mark::latestForSubmission($submissionId, 'primary');
        $annotations = Annotation::forSubmission($submissionId);

        // Decrypt mark schemes for display only within this authorized view.
        $markSchemes = [];
        foreach ($questions as $q) {
            $markSchemes[(int) $q['id']] = Question::decryptedMarkScheme($q);
        }

        require __DIR__ . '/../views/teacher/mark_submission.php';
    }

    public static function saveMark(int $submissionId): void
    {
        $user = AuthController::requireRole([User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER]);
        AuthController::verifyCsrf();

        foreach ((array) ($_POST['scores'] ?? []) as $questionId => $score) {
            if ($score === '') {
                continue;
            }
            $comment = $_POST['comments'][$questionId] ?? null;
            Mark::record($submissionId, (int) $questionId, (int) $user['id'], (float) $score, $comment !== null ? (string) $comment : null, 'primary');
        }

        Submission::setStatus($submissionId, 'marked');
        header('Location: /assessment/teacher/marking/' . $submissionId);
        exit;
    }

    /** Persists a Fabric.js/PDF.js vector overlay for one page as JSON. */
    public static function saveAnnotation(int $submissionId): void
    {
        $user = AuthController::requireRole([User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER]);

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        AuthController::bootSession();
        if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($input['csrf_token'] ?? ''))) {
            http_response_code(419);
            echo json_encode(['error' => 'Invalid form token.']);
            exit;
        }

        Annotation::save($submissionId, (int) ($input['page'] ?? 1), (int) $user['id'], (array) ($input['fabric_json'] ?? []));
        header('Content-Type: application/json');
        echo json_encode(['saved' => true]);
    }
}
