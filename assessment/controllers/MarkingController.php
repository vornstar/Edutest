<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/PaperController.php';
require_once __DIR__ . '/../models/Submission.php';
require_once __DIR__ . '/../models/TestAssignment.php';
require_once __DIR__ . '/../models/Paper.php';
require_once __DIR__ . '/../models/Question.php';
require_once __DIR__ . '/../models/Mark.php';
require_once __DIR__ . '/../models/Annotation.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/ClassRoster.php';
require_once __DIR__ . '/../models/Moderation.php';
require_once __DIR__ . '/../models/CustomStamp.php';
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

    /**
     * Marking a submission requires MANAGE authority over its paper (the
     * paper's creator, an Admin, or a Subject Leader over that subject) -
     * not just the wider read-only VIEW visibility every subject teacher
     * now has via the results page (see PaperController::canViewPaper).
     * Without this, a colleague who can merely see a paper's results could
     * follow the "Mark" link and edit another teacher's class's marks.
     */
    private static function requireMarkable(int $submissionId, array $user): array
    {
        $submission = Submission::find($submissionId);
        if (!$submission) {
            http_response_code(404);
            exit;
        }
        $assignment = TestAssignment::find((int) $submission['assignment_id']);
        $paper = Paper::find((int) $assignment['paper_id']);
        if (!$paper || !PaperController::canManagePaper($user, $paper)) {
            http_response_code(404);
            exit;
        }
        return $submission;
    }

    /**
     * Annotation saving is reached from two different screens sharing the
     * same JS/endpoint: primary marking (requireMarkable() above) AND
     * moderation review (moderation_review.php, reusing canvas-annotate.js)
     * - a secondary marker doing a moderation review very often does NOT
     * otherwise manage the paper, so they need their own, wider check here
     * rather than being blocked by requireMarkable()'s stricter one.
     */
    private static function requireAnnotatable(int $submissionId, array $user): array
    {
        $submission = Submission::find($submissionId);
        if (!$submission) {
            http_response_code(404);
            exit;
        }
        if (Moderation::isSecondaryMarker($submissionId, (int) $user['id'])) {
            return $submission;
        }
        $assignment = TestAssignment::find((int) $submission['assignment_id']);
        $paper = Paper::find((int) $assignment['paper_id']);
        if (!$paper || !PaperController::canManagePaper($user, $paper)) {
            http_response_code(404);
            exit;
        }
        return $submission;
    }

    public static function markSubmission(int $submissionId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $submission = self::requireMarkable($submissionId, $user);

        $assignment = TestAssignment::find((int) $submission['assignment_id']);
        $paper = Paper::find((int) $assignment['paper_id']);
        $questions = Question::forPaper((int) $paper['id']);

        $answers = Submission::answers($submissionId);
        $selfMarks = Submission::selfMarks($submissionId);
        $primaryMarks = Mark::latestForSubmission($submissionId, 'primary');
        $annotations = Annotation::forSubmission($submissionId);
        $nextUnmarkedId = Submission::nextUnmarked($submissionId, (int) $paper['id']);
        $customStamps = CustomStamp::forUser((int) $user['id']);

        // The teacher's own marking layer (whatever version of $annotations
        // belongs to them) is unaffected by the student-version picker below.
        $teacherAnnotations = [];
        foreach ($annotations as $a) {
            if ((int) $a['marker_id'] === (int) $user['id']) {
                $teacherAnnotations[(int) $a['page_number']] = json_decode($a['data_json'], true);
            }
        }

        // The student's own in-PDF writing can have several versions (see
        // "Start over" - Submission::startNewAnnotationVersion). $annotations
        // above always carries the latest; ?student_version=N lets the
        // marker look back at an earlier one instead, defaulting to latest
        // when absent or invalid.
        $studentId = (int) $submission['student_id'];
        $studentVersions = Annotation::versionsForMarker($submissionId, $studentId);
        $latestStudentVersion = $studentVersions ? (int) end($studentVersions)['version'] : null;
        $selectedStudentVersion = !empty($_GET['student_version']) ? (int) $_GET['student_version'] : $latestStudentVersion;
        $viewingOldStudentVersion = $selectedStudentVersion !== null && $selectedStudentVersion !== $latestStudentVersion
            && in_array($selectedStudentVersion, array_column($studentVersions, 'version'), true);
        if (!$viewingOldStudentVersion) {
            $selectedStudentVersion = $latestStudentVersion;
        }

        $studentRows = $viewingOldStudentVersion
            ? Annotation::forMarkerVersion($submissionId, $studentId, $selectedStudentVersion)
            : array_filter($annotations, static fn(array $a): bool => (int) $a['marker_id'] === $studentId);
        $studentAnnotations = [];
        foreach ($studentRows as $a) {
            $studentAnnotations[(int) $a['page_number']] = json_decode($a['data_json'], true);
        }

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
        self::requireMarkable($submissionId, $user);

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
        self::requireAnnotatable($submissionId, $user);

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        AuthController::bootSession();
        if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($input['csrf_token'] ?? ''))) {
            http_response_code(419);
            echo json_encode(['error' => 'Invalid form token.']);
            exit;
        }

        Annotation::save($submissionId, (int) ($input['page'] ?? 1), (int) $user['id'], 1, (array) ($input['fabric_json'] ?? []));
        header('Content-Type: application/json');
        echo json_encode(['saved' => true]);
    }
}
