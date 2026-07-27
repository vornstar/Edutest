<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../models/Paper.php';
require_once __DIR__ . '/../models/Question.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/TestAssignment.php';
require_once __DIR__ . '/../models/Submission.php';
require_once __DIR__ . '/../models/Mark.php';
require_once __DIR__ . '/../services/OneDriveService.php';

/**
 * Paper/question bank management: digital question authoring, bulk CSV
 * import, and PDF exam paper + mark scheme uploads to OneDrive (SRS 5).
 */
final class PaperController
{
    /**
     * Who may manage (view/edit/delete) a given paper: its own creator,
     * an Admin (full system access), or a Subject Leader whose managed
     * subject (see Admin > Users) matches the paper's subject - department-
     * wide oversight per SRS 3.2, not limited to their own papers.
     */
    public static function canManagePaper(array $user, array $paper): bool
    {
        if ((int) $paper['created_by'] === (int) $user['id']) {
            return true;
        }
        if ($user['role'] === User::ROLE_ADMIN) {
            return true;
        }
        if ($user['role'] === User::ROLE_SUBJECT_LEADER && !empty($user['managed_subject'])) {
            return strcasecmp((string) $user['managed_subject'], (string) ($paper['subject'] ?? '')) === 0;
        }
        return false;
    }

    public static function requireManageable(int $paperId, array $user): array
    {
        $paper = Paper::find($paperId);
        if (!$paper || !self::canManagePaper($user, $paper)) {
            http_response_code(404);
            exit;
        }
        return $paper;
    }

    public static function index(): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $papers = Paper::visibleTo($user);
        require __DIR__ . '/../views/teacher/papers_index.php';
    }

    public static function createForm(): void
    {
        AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        require __DIR__ . '/../views/teacher/paper_create.php';
    }

    public static function store(): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();

        $type = ($_POST['type'] ?? 'digital') === 'pdf' ? 'pdf' : 'digital';

        $paperId = Paper::create([
            'title' => trim((string) ($_POST['title'] ?? 'Untitled paper')),
            'subject' => trim((string) ($_POST['subject'] ?? '')) ?: null,
            'type' => $type,
            'created_by' => $user['id'],
            'duration_minutes' => !empty($_POST['duration_minutes']) ? (int) $_POST['duration_minutes'] : null,
        ]);

        if ($type === 'pdf' && !empty($_FILES['paper_pdf']['tmp_name'])) {
            self::attachPdfUploads($paperId, (int) $user['id']);
        }

        header('Location: /assessment/teacher/papers/' . $paperId);
        exit;
    }

    private static function attachPdfUploads(int $paperId, int $actingUserId): void
    {
        $drive = new OneDriveService($actingUserId);

        $pdfItemId = null;
        if (!empty($_FILES['paper_pdf']['tmp_name']) && is_uploaded_file($_FILES['paper_pdf']['tmp_name'])) {
            self::assertPdf($_FILES['paper_pdf']);
            $content = file_get_contents($_FILES['paper_pdf']['tmp_name']);
            $pdfItemId = $drive->uploadPaperPdf($paperId, 'paper.pdf', $content);
        }

        $markSchemeItemId = null;
        if (!empty($_FILES['mark_scheme_pdf']['tmp_name']) && is_uploaded_file($_FILES['mark_scheme_pdf']['tmp_name'])) {
            self::assertPdf($_FILES['mark_scheme_pdf']);
            $content = file_get_contents($_FILES['mark_scheme_pdf']['tmp_name']);
            $markSchemeItemId = $drive->uploadPaperPdf($paperId, 'mark_scheme.pdf', $content);
        }

        Paper::attachPdf($paperId, (string) $pdfItemId, $markSchemeItemId);
    }

    private static function assertPdf(array $file): void
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if ($mime !== 'application/pdf') {
            http_response_code(422);
            echo 'Only PDF files are accepted.';
            exit;
        }
    }

    public static function show(int $paperId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $paper = self::requireManageable($paperId, $user);
        $questions = Question::forPaper($paperId);
        $canDelete = !Paper::hasSubmissions($paperId);
        $selfTest = TestAssignment::findSelfTest($paperId, (int) $user['id']);
        $selfTestSubmission = $selfTest ? Submission::findByAssignmentAndStudent((int) $selfTest['id'], (int) $user['id']) : null;
        require __DIR__ . '/../views/teacher/paper_show.php';
    }

    /**
     * Every submission across every class this paper has been assigned to,
     * with its status and (if marked) total score - this is both the
     * "results" view and the place to find/re-open an already-marked
     * submission, since MarkingController::markSubmission has no status
     * restriction.
     */
    public static function results(int $paperId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $paper = self::requireManageable($paperId, $user);

        $questions = Question::forPaper($paperId);
        $maxTotal = array_sum(array_column($questions, 'max_marks'));

        $assignments = TestAssignment::forPaper($paperId);
        $rows = [];
        foreach ($assignments as $assignment) {
            foreach (Submission::forAssignment((int) $assignment['id']) as $submission) {
                $isMarked = in_array($submission['status'], ['marked', 'moderated'], true);
                $rows[] = [
                    'submission' => $submission,
                    'class_name' => $assignment['class_name'],
                    'score' => $isMarked ? Mark::totalScore((int) $submission['id'], 'primary') : null,
                ];
            }
        }

        require __DIR__ . '/../views/teacher/paper_results.php';
    }

    public static function addQuestion(int $paperId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();
        self::requireManageable($paperId, $user);

        $options = null;
        if (($_POST['type'] ?? '') === 'mcq') {
            $options = array_values(array_filter(array_map('trim', explode("\n", (string) ($_POST['options'] ?? '')))));
        }

        Question::create([
            'paper_id' => $paperId,
            'section' => trim((string) ($_POST['section'] ?? '')) ?: null,
            'order_index' => (int) ($_POST['order_index'] ?? 0),
            'type' => $_POST['type'] ?? 'short_answer',
            'question_text' => (string) ($_POST['question_text'] ?? ''),
            'options' => $options,
            'correct_option' => $_POST['correct_option'] ?? null,
            'max_marks' => (float) ($_POST['max_marks'] ?? 1),
            'mark_scheme' => $_POST['mark_scheme'] ?? null,
            'model_answer' => $_POST['model_answer'] ?? null,
        ]);

        header('Location: /assessment/teacher/papers/' . $paperId);
        exit;
    }

    /**
     * Bulk imports questions from a CSV with columns:
     * section,type,question_text,options,correct_option,max_marks,mark_scheme,model_answer
     * options is a "|" separated list for mcq rows.
     */
    public static function bulkImport(int $paperId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();
        self::requireManageable($paperId, $user);

        if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            http_response_code(422);
            echo 'No CSV file uploaded.';
            exit;
        }

        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $header = fgetcsv($handle);
        $expected = ['section', 'type', 'question_text', 'options', 'correct_option', 'max_marks', 'mark_scheme', 'model_answer'];
        if ($header === false || array_map('trim', $header) !== $expected) {
            fclose($handle);
            http_response_code(422);
            echo 'CSV header must be: ' . implode(',', $expected);
            exit;
        }

        $index = 0;
        $imported = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < count($expected)) {
                continue;
            }
            [$section, $type, $questionText, $options, $correctOption, $maxMarks, $markScheme, $modelAnswer] = $row;

            Question::create([
                'paper_id' => $paperId,
                'section' => $section ?: null,
                'order_index' => $index++,
                'type' => in_array($type, ['mcq', 'short_answer', 'extended_text'], true) ? $type : 'short_answer',
                'question_text' => $questionText,
                'options' => $type === 'mcq' && $options !== '' ? array_map('trim', explode('|', $options)) : null,
                'correct_option' => $correctOption ?: null,
                'max_marks' => (float) ($maxMarks !== '' ? $maxMarks : 1),
                'mark_scheme' => $markScheme ?: null,
                'model_answer' => $modelAnswer ?: null,
            ]);
            $imported++;
        }
        fclose($handle);

        header('Location: /assessment/teacher/papers/' . $paperId . '?imported=' . $imported);
        exit;
    }

    public static function publish(int $paperId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();
        self::requireManageable($paperId, $user);

        Paper::publish($paperId);
        header('Location: /assessment/teacher/papers/' . $paperId);
        exit;
    }

    /**
     * Replaces the exam paper PDF and/or mark scheme PDF on an existing
     * PDF-type paper - either field can be resubmitted independently.
     */
    public static function updatePdf(int $paperId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();
        $paper = self::requireManageable($paperId, $user);

        if ($paper['type'] !== 'pdf') {
            http_response_code(422);
            echo 'Only PDF-type papers have files to replace.';
            exit;
        }

        $drive = new OneDriveService((int) $user['id']);

        if (!empty($_FILES['paper_pdf']['tmp_name']) && is_uploaded_file($_FILES['paper_pdf']['tmp_name'])) {
            self::assertPdf($_FILES['paper_pdf']);
            $content = file_get_contents($_FILES['paper_pdf']['tmp_name']);
            $itemId = $drive->uploadPaperPdf($paperId, 'paper.pdf', $content);
            Paper::replacePdfFile($paperId, 'pdf_drive_item_id', $itemId);
        }

        if (!empty($_FILES['mark_scheme_pdf']['tmp_name']) && is_uploaded_file($_FILES['mark_scheme_pdf']['tmp_name'])) {
            self::assertPdf($_FILES['mark_scheme_pdf']);
            $content = file_get_contents($_FILES['mark_scheme_pdf']['tmp_name']);
            $itemId = $drive->uploadPaperPdf($paperId, 'mark_scheme.pdf', $content);
            Paper::replacePdfFile($paperId, 'mark_scheme_drive_item_id', $itemId);
        }

        header('Location: /assessment/teacher/papers/' . $paperId);
        exit;
    }

    /**
     * Deletes a paper (and everything under it - questions, assignments,
     * submissions, marks - via ON DELETE CASCADE). Refused once any
     * student has actually started/submitted work against it, so this
     * can't accidentally destroy real student data - see
     * Paper::hasSubmissions().
     */
    public static function destroy(int $paperId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();
        self::requireManageable($paperId, $user);

        if (Paper::hasSubmissions($paperId)) {
            http_response_code(409);
            echo 'This paper has student submissions against it and cannot be deleted.';
            exit;
        }

        Paper::delete($paperId);
        header('Location: /assessment/teacher/papers');
        exit;
    }

    /**
     * Starts (or resumes) a self-test: a real assignment/submission the
     * teacher takes as if they were a student, so autosave/PDF typing/
     * submit/marking can all be tried for real before any student sees
     * the paper - see TestController::takeSelfTest().
     */
    public static function startTest(int $paperId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();
        self::requireManageable($paperId, $user);

        $assignment = TestAssignment::findSelfTest($paperId, (int) $user['id']);
        $assignmentId = $assignment ? (int) $assignment['id'] : TestAssignment::create($paperId, null, (int) $user['id'], null, false);
        Submission::startOrGet($assignmentId, (int) $user['id']);

        header('Location: /assessment/teacher/self-test/' . $assignmentId);
        exit;
    }

    /** Deletes a paper's self-test assignment/submission (and everything under it) so testing can be repeated from scratch, or cleaned up before real use. */
    public static function deleteTest(int $paperId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();
        self::requireManageable($paperId, $user);

        $assignment = TestAssignment::findSelfTest($paperId, (int) $user['id']);
        if ($assignment) {
            TestAssignment::delete((int) $assignment['id']);
        }

        header('Location: /assessment/teacher/papers/' . $paperId);
        exit;
    }
}
