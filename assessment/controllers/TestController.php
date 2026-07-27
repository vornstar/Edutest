<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/PaperController.php';
require_once __DIR__ . '/../models/Paper.php';
require_once __DIR__ . '/../models/Question.php';
require_once __DIR__ . '/../models/ClassRoster.php';
require_once __DIR__ . '/../models/TestAssignment.php';
require_once __DIR__ . '/../models/Submission.php';
require_once __DIR__ . '/../models/Annotation.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../services/TeamsService.php';
require_once __DIR__ . '/../services/OneDriveService.php';

final class TestController
{
    /**
     * "What does the student see?" - renders the exact same take_digital/
     * take_pdf views a student would get, in a read-only preview mode: no
     * Submission row is created, autosave/submit/scan are all disabled.
     * Keyed by paper (not a specific assignment/class), since assignment
     * only adds a due date / Teams link - the content shown is the same
     * regardless of which class it's assigned to.
     */
    public static function preview(int $paperId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $paper = PaperController::requireManageable($paperId, $user);

        $questions = Question::forPaper($paperId);
        $answers = [];
        $submission = ['id' => 0];
        $previewMode = true;

        if ($paper['type'] === 'digital') {
            require __DIR__ . '/../views/student/take_digital.php';
        } else {
            require __DIR__ . '/../views/student/take_pdf.php';
        }
    }

    /**
     * Starts (or resumes) a teacher-portal user's own self-test - the real
     * take_digital/take_pdf flow, fully interactive, against a real
     * submission only they can ever see. Created via
     * PaperController::startTest(). Distinct from preview() (which never
     * creates a submission and is read-only) - this is for actually trying
     * typing, autosave, and submitting before any real student does.
     */
    public static function takeSelfTest(int $assignmentId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $assignment = TestAssignment::find($assignmentId);
        if (!$assignment || !TestAssignment::isSelfTest($assignment) || (int) $assignment['assigned_by'] !== (int) $user['id']) {
            http_response_code(404);
            exit;
        }

        $paper = Paper::find((int) $assignment['paper_id']);
        $submission = Submission::startOrGet($assignmentId, (int) $user['id']);
        $questions = Question::forPaper((int) $paper['id']);
        $answers = Submission::answers((int) $submission['id']);

        if ($paper['type'] === 'digital') {
            require __DIR__ . '/../views/student/take_digital.php';
        } else {
            require __DIR__ . '/../views/student/take_pdf.php';
        }
    }

    /**
     * Authorizes access to a submission's own student-facing actions
     * (autosave, submit, scan upload, in-PDF annotation): either the real
     * student it belongs to, or - for a self-test - the teacher-portal
     * user who created it, so the whole real flow can be tried end to end
     * without needing an actual student account.
     */
    private static function authorizeSubmissionOwner(int $submissionId): array
    {
        $user = AuthController::requireLogin();
        $submission = Submission::find($submissionId);
        if (!$submission || (int) $submission['student_id'] !== (int) $user['id']) {
            http_response_code(404);
            exit;
        }

        if ($user['role'] === User::ROLE_STUDENT) {
            return [$user, $submission];
        }

        if (in_array($user['role'], User::TEACHER_PORTAL_ROLES, true)) {
            $assignment = TestAssignment::find((int) $submission['assignment_id']);
            if ($assignment && TestAssignment::isSelfTest($assignment) && (int) $assignment['assigned_by'] === (int) $user['id']) {
                return [$user, $submission];
            }
        }

        http_response_code(404);
        exit;
    }

    // --- Teacher: assign a paper to a class, optionally pushing to Teams ---

    public static function assignForm(int $paperId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $paper = Paper::find($paperId);
        if (!$paper) {
            http_response_code(404);
            exit;
        }
        $classes = ClassRoster::forTeacher((int) $user['id']);
        require __DIR__ . '/../views/teacher/assign_form.php';
    }

    public static function assign(int $paperId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();

        $classId = (int) ($_POST['class_id'] ?? 0);
        $class = ClassRoster::find($classId);
        if (!$class) {
            http_response_code(404);
            exit;
        }

        $syncToTeams = !empty($_POST['sync_to_teams']) && !empty($class['teams_class_id']);
        $dueAt = !empty($_POST['due_at']) ? (string) $_POST['due_at'] : null;
        $selfMarkingEnabled = !empty($_POST['self_marking_enabled']);

        $assignmentId = TestAssignment::create($paperId, $classId, (int) $user['id'], $dueAt, $syncToTeams, $selfMarkingEnabled);

        if ($syncToTeams) {
            $paper = Paper::find($paperId);
            $deepLink = self::deepLinkUrl($assignmentId);
            $teams = new TeamsService((int) $user['id']);
            $teams->pushAssignment($assignmentId, (string) $class['teams_class_id'], (string) $paper['title'], $dueAt, $deepLink);
        }

        header('Location: /assessment/teacher/classes/' . $classId);
        exit;
    }

    /**
     * Flips self-marking on/off for an already-assigned test - lets a
     * teacher hold it off while the class is still sitting the test, then
     * enable it once everyone's finished (or due date has passed) so early
     * finishers can't see the mark scheme while others are still working.
     */
    public static function toggleSelfMarking(int $assignmentId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();

        $assignment = TestAssignment::find($assignmentId);
        if (!$assignment) {
            http_response_code(404);
            exit;
        }
        $paper = PaperController::requireManageable((int) $assignment['paper_id'], $user);

        TestAssignment::setSelfMarking($assignmentId, empty($assignment['self_marking_enabled']));

        header('Location: /assessment/teacher/classes/' . (int) $assignment['class_id']);
        exit;
    }

    private static function deepLinkUrl(int $assignmentId): string
    {
        $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
        return "{$scheme}://{$_SERVER['HTTP_HOST']}/assessment/student/assignments/{$assignmentId}";
    }

    // --- Student: take a test ---

    public static function take(int $assignmentId): void
    {
        $user = AuthController::requireRole([User::ROLE_STUDENT]);
        $assignment = TestAssignment::find($assignmentId);
        if (!$assignment || !ClassRoster::isMember((int) $assignment['class_id'], (int) $user['id'])) {
            http_response_code(404);
            exit;
        }

        $paper = Paper::find((int) $assignment['paper_id']);
        $submission = Submission::startOrGet($assignmentId, (int) $user['id']);
        $questions = Question::forPaper((int) $paper['id']);
        $answers = Submission::answers((int) $submission['id']);

        if ($paper['type'] === 'digital') {
            require __DIR__ . '/../views/student/take_digital.php';
        } else {
            require __DIR__ . '/../views/student/take_pdf.php';
        }
    }

    public static function autosave(int $submissionId): void
    {
        [$user, $submission] = self::authorizeSubmissionOwner($submissionId);
        if ($submission['status'] !== 'in_progress') {
            http_response_code(409);
            echo json_encode(['error' => 'Submission already finalized.']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        AuthController::bootSession();
        if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($input['csrf_token'] ?? ''))) {
            http_response_code(419);
            echo json_encode(['error' => 'Invalid form token.']);
            exit;
        }

        Submission::autosaveAnswer($submissionId, (int) $input['question_id'], (string) $input['answer_text']);
        header('Content-Type: application/json');
        echo json_encode(['saved' => true, 'at' => date('c')]);
    }

    /**
     * Saves one page's worth of in-PDF typing/drawing as Fabric.js JSON -
     * this is the "type directly on the PDF" answer mechanism for PDF-mode
     * papers, an alternative to the plain-text answer booklet. Stored via
     * the same Annotation model teacher marking uses, keyed by the
     * student's own user id as marker_id, so it's a clean separate layer
     * from any teacher/moderator annotation on the same submission.
     */
    public static function saveAnnotation(int $submissionId): void
    {
        [$user, $submission] = self::authorizeSubmissionOwner($submissionId);
        if ($submission['status'] !== 'in_progress') {
            http_response_code(409);
            echo json_encode(['error' => 'Submission already finalized.']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        AuthController::bootSession();
        if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($input['csrf_token'] ?? ''))) {
            http_response_code(419);
            echo json_encode(['error' => 'Invalid form token.']);
            exit;
        }

        Annotation::save($submissionId, (int) ($input['page'] ?? 1), (int) $user['id'], (array) ($input['fabric_json'] ?? []));
        header('Content-Type: application/json');
        echo json_encode(['saved' => true, 'at' => date('c')]);
    }

    public static function submit(int $submissionId): void
    {
        AuthController::verifyCsrf();
        [$user, $submission] = self::authorizeSubmissionOwner($submissionId);

        Submission::submit($submissionId);

        if ($user['role'] === User::ROLE_STUDENT) {
            header('Location: /assessment/student/submissions/' . $submissionId);
        } else {
            // Self-test: go straight to marking so the whole flow - type, submit, mark - can be tried in one sitting.
            header('Location: /assessment/teacher/marking/' . $submissionId);
        }
        exit;
    }

    /** Upload a scanned handwritten script for a PDF-mode assignment. */
    public static function uploadScan(int $submissionId): void
    {
        AuthController::verifyCsrf();
        [$user, $submission] = self::authorizeSubmissionOwner($submissionId);

        if (empty($_FILES['scan']['tmp_name']) || !is_uploaded_file($_FILES['scan']['tmp_name'])) {
            http_response_code(422);
            echo 'No file uploaded.';
            exit;
        }

        $assignment = TestAssignment::find((int) $submission['assignment_id']);
        $content = file_get_contents($_FILES['scan']['tmp_name']);
        $ext = pathinfo($_FILES['scan']['name'], PATHINFO_EXTENSION) ?: 'pdf';

        $drive = new OneDriveService((int) $user['id']);
        $itemId = $drive->uploadScannedScript((int) $assignment['paper_id'], (int) $user['id'], $content, strtolower($ext));

        Submission::attachScan($submissionId, $itemId);
        if ($user['role'] === User::ROLE_STUDENT) {
            header('Location: /assessment/student/submissions/' . $submissionId);
        } else {
            header('Location: /assessment/teacher/marking/' . $submissionId);
        }
        exit;
    }

    // --- Student: self-marking workflow (SRS 6.2) ---

    public static function selfMarkForm(int $submissionId): void
    {
        $user = AuthController::requireRole([User::ROLE_STUDENT]);
        $submission = Submission::find($submissionId);
        if (!$submission || (int) $submission['student_id'] !== (int) $user['id']) {
            http_response_code(404);
            exit;
        }
        $assignment = TestAssignment::find((int) $submission['assignment_id']);
        $paper = Paper::find((int) $assignment['paper_id']);

        if (!$assignment['self_marking_enabled'] || !in_array($submission['status'], ['submitted', 'self_marked'], true)) {
            http_response_code(403);
            require __DIR__ . '/../views/partials/forbidden.php';
            exit;
        }

        $questions = Question::forPaper((int) $paper['id']);
        $answers = Submission::answers($submissionId);
        $selfMarks = Submission::selfMarks($submissionId);
        require __DIR__ . '/../views/student/self_mark.php';
    }

    public static function selfMarkSubmit(int $submissionId): void
    {
        $user = AuthController::requireRole([User::ROLE_STUDENT]);
        AuthController::verifyCsrf();

        $submission = Submission::find($submissionId);
        if (!$submission || (int) $submission['student_id'] !== (int) $user['id']) {
            http_response_code(404);
            exit;
        }

        foreach ((array) ($_POST['marks'] ?? []) as $questionId => $mark) {
            $reflection = $_POST['reflections'][$questionId] ?? null;
            Submission::recordSelfMark($submissionId, (int) $questionId, (float) $mark, $reflection !== null ? (string) $reflection : null);
        }

        Submission::completeSelfMarking($submissionId);
        header('Location: /assessment/student/submissions/' . $submissionId);
        exit;
    }
}
