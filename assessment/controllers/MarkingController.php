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
require_once __DIR__ . '/../models/ClassRoster.php';
require_once __DIR__ . '/../services/TeamsService.php';

/**
 * Teacher on-screen marking (SRS 7.1) and canvas annotation persistence
 * (SRS 7.2). The mark scheme is decrypted here, server-side, purely for
 * display to an authorized marker - never sent anywhere else in cleartext.
 */
final class MarkingController
{
    public static function queue(): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $papers = Paper::byCreator((int) $user['id']);
        require __DIR__ . '/../views/teacher/marking_queue.php';
    }

    public static function markSubmission(int $submissionId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);

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
        $nextUnmarkedId = Submission::nextUnmarked($submissionId, (int) $paper['id']);

        // Decrypt mark schemes for display only within this authorized view.
        $markSchemes = [];
        foreach ($questions as $q) {
            $markSchemes[(int) $q['id']] = Question::decryptedMarkScheme($q);
        }

        require __DIR__ . '/../views/teacher/mark_submission.php';
    }

    public static function saveMark(int $submissionId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();

        foreach ((array) ($_POST['scores'] ?? []) as $questionId => $score) {
            if ($score === '') {
                continue;
            }
            $comment = $_POST['comments'][$questionId] ?? null;
            Mark::record($submissionId, (int) $questionId, (int) $user['id'], (float) $score, $comment !== null ? (string) $comment : null, 'primary');
        }

        // Whole-paper overall mark (pdf-type papers with no question breakdown) - see Mark::record().
        if (isset($_POST['overall_score']) && $_POST['overall_score'] !== '') {
            $comment = $_POST['overall_comment'] ?? null;
            Mark::record($submissionId, null, (int) $user['id'], (float) $_POST['overall_score'], $comment !== null ? (string) $comment : null, 'primary');
        }

        Submission::setStatus($submissionId, 'marked');
        self::pushGradeToTeamsIfLinked($submissionId, (int) $user['id']);

        header('Location: /assessment/teacher/marking/' . $submissionId);
        exit;
    }

    /**
     * If this assignment was pushed to Teams (see TestController::assign),
     * write the total score back to the matching Teams submission and
     * release it so the student/gradebook sees it there too. Best-effort -
     * the local mark is already saved regardless, so a Graph failure here
     * (e.g. the roster hasn't been re-synced since this student joined, so
     * their aad_object_id isn't known yet) must not block marking.
     */
    private static function pushGradeToTeamsIfLinked(int $submissionId, int $actingUserId): void
    {
        $submission = Submission::find($submissionId);
        $assignment = TestAssignment::find((int) $submission['assignment_id']);
        if (!$assignment || empty($assignment['teams_assignment_id']) || empty($assignment['class_id'])) {
            return;
        }

        $class = ClassRoster::find((int) $assignment['class_id']);
        $student = User::find((int) $submission['student_id']);
        if (!$class || empty($class['teams_class_id']) || empty($student['aad_object_id'])) {
            return;
        }

        $score = Mark::totalScore($submissionId, 'primary');

        try {
            $teams = new TeamsService($actingUserId);
            $teams->pushGrade((string) $class['teams_class_id'], (string) $assignment['teams_assignment_id'], (string) $student['aad_object_id'], $score);
        } catch (Throwable $e) {
            error_log('Teams grade push failed for submission ' . $submissionId . ': ' . $e->getMessage());
        }
    }

    /** Persists a Fabric.js/PDF.js vector overlay for one page as JSON. */
    public static function saveAnnotation(int $submissionId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);

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
