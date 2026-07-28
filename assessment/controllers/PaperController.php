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

    /**
     * Who may VIEW a paper (its detail page, results) without necessarily
     * being able to edit/delete it: anyone who can manage it, plus any
     * Teacher whose subject (see Admin > Users) matches the paper's -
     * read-only visibility into a subject's other papers, distinct from
     * the Subject Leader's additional manage/delete authority over them.
     */
    public static function canViewPaper(array $user, array $paper): bool
    {
        if (self::canManagePaper($user, $paper)) {
            return true;
        }
        if ($user['role'] === User::ROLE_TEACHER && !empty($user['managed_subject'])) {
            return strcasecmp((string) $user['managed_subject'], (string) ($paper['subject'] ?? '')) === 0;
        }
        return false;
    }

    public static function requireViewable(int $paperId, array $user): array
    {
        $paper = Paper::find($paperId);
        if (!$paper || !self::canViewPaper($user, $paper)) {
            http_response_code(404);
            exit;
        }
        return $paper;
    }

    public static function index(): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $papers = Paper::visibleTo($user);
        require_once __DIR__ . '/../models/PaperGroup.php';
        $groups = PaperGroup::all();
        require __DIR__ . '/../views/teacher/papers_index.php';
    }

    public static function createForm(): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        require_once __DIR__ . '/../models/Subject.php';
        require_once __DIR__ . '/../models/PaperGroup.php';
        $subjects = Subject::all();
        $groups = PaperGroup::all();
        $inboxFiles = (new OneDriveService((int) $user['id']))->listInbox();
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
            'group_id' => self::resolveGroupId($user),
            'type' => $type,
            'created_by' => $user['id'],
            'max_marks' => $type === 'pdf' && !empty($_POST['max_marks']) ? (float) $_POST['max_marks'] : null,
            'duration_minutes' => !empty($_POST['duration_minutes']) ? (int) $_POST['duration_minutes'] : null,
        ]);

        $pdfProvided = !empty($_FILES['paper_pdf']['tmp_name']) || !empty($_POST['paper_pdf_inbox_id']);
        $markSchemeProvided = !empty($_FILES['mark_scheme_pdf']['tmp_name']) || !empty($_POST['mark_scheme_pdf_inbox_id']);
        if ($type === 'pdf' && ($pdfProvided || $markSchemeProvided)) {
            self::attachPdfUploads($paperId, (int) $user['id']);
        }

        header('Location: /assessment/teacher/papers/' . $paperId);
        exit;
    }

    /** A new group name (POST new_group_name) takes priority over the group_id dropdown - lets a teacher create-and-use a group in one step without a separate trip to manage groups first. */
    private static function resolveGroupId(array $user): ?int
    {
        require_once __DIR__ . '/../models/PaperGroup.php';

        $newGroupName = trim((string) ($_POST['new_group_name'] ?? ''));
        if ($newGroupName !== '') {
            try {
                return PaperGroup::create($newGroupName, (int) $user['id']);
            } catch (PDOException $e) {
                // Duplicate name (uq_paper_group_name) - reuse the existing group of that name instead of failing the whole paper creation.
                foreach (PaperGroup::all() as $g) {
                    if (strcasecmp($g['name'], $newGroupName) === 0) {
                        return (int) $g['id'];
                    }
                }
                return null;
            }
        }

        return !empty($_POST['group_id']) ? (int) $_POST['group_id'] : null;
    }

    /** Edits which group a paper belongs to at any time, e.g. after creating the group it should have been in from the start. */
    public static function updateGroup(int $paperId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();
        self::requireManageable($paperId, $user);

        $groupId = self::resolveGroupId($user);
        Paper::setGroup($paperId, $groupId);

        header('Location: /assessment/teacher/papers/' . $paperId);
        exit;
    }

    private static function attachPdfUploads(int $paperId, int $actingUserId): void
    {
        $drive = new OneDriveService($actingUserId);

        $pdfItemId = self::resolvePdfSlot($drive, $paperId, 'paper_pdf', 'paper.pdf');
        $markSchemeItemId = self::resolvePdfSlot($drive, $paperId, 'mark_scheme_pdf', 'mark_scheme.pdf');

        Paper::attachPdf($paperId, (string) $pdfItemId, $markSchemeItemId);
    }

    /**
     * Fills one PDF slot (exam paper / mark scheme) either from a direct
     * file upload or, if the teacher instead picked one, from a file
     * already sitting in the Bulk upload Inbox (see PaperController::
     * inboxForm/OneDriveService::attachInboxItem) - the same "attach"
     * operation the standalone Inbox page uses, just reachable from the
     * paper-creation form's own file picker instead of a separate trip.
     * An Inbox pick (POST {$fieldName}_inbox_id) always wins over a
     * simultaneously-submitted file upload for the same slot.
     */
    private static function resolvePdfSlot(OneDriveService $drive, int $paperId, string $fieldName, string $filename): ?string
    {
        $inboxItemId = trim((string) ($_POST[$fieldName . '_inbox_id'] ?? ''));
        if ($inboxItemId !== '') {
            return $drive->attachInboxItem($inboxItemId, $paperId, $filename);
        }

        if (!empty($_FILES[$fieldName]['tmp_name']) && is_uploaded_file($_FILES[$fieldName]['tmp_name'])) {
            self::assertPdf($_FILES[$fieldName]);
            $content = file_get_contents($_FILES[$fieldName]['tmp_name']);
            return $drive->uploadPaperPdf($paperId, $filename, $content);
        }

        return null;
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

    private static function isPdfFile(string $tmpName): bool
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmpName);
        finfo_close($finfo);
        return $mime === 'application/pdf';
    }

    /**
     * "Bulk upload": a shared OneDrive holding area (see OneDriveService::
     * uploadToInbox/listInbox) for exam paper/mark scheme PDFs uploaded
     * ahead of being attached to a specific paper - lets a teacher drop in
     * a whole batch at once (e.g. a term's worth of past papers) and then
     * work through attaching each one to its paper whenever convenient,
     * rather than one file-picker round trip per paper.
     */
    public static function inboxForm(): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);

        $drive = new OneDriveService((int) $user['id']);
        $files = $drive->listInbox();

        $pdfPapers = array_values(array_filter(
            Paper::visibleTo($user),
            static fn(array $p): bool => $p['type'] === 'pdf' && self::canManagePaper($user, $p)
        ));

        require __DIR__ . '/../views/teacher/paper_inbox.php';
    }

    /** Uploads every selected file into the Inbox - non-PDF files are silently skipped rather than aborting the whole batch, since a bulk multi-file picker will often catch a stray non-PDF. */
    public static function inboxUpload(): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();

        $files = $_FILES['files'] ?? null;
        $uploaded = 0;
        $skipped = 0;

        if ($files && is_array($files['tmp_name'] ?? null)) {
            $drive = new OneDriveService((int) $user['id']);
            foreach ($files['tmp_name'] as $i => $tmpName) {
                if (empty($tmpName) || !is_uploaded_file($tmpName)) {
                    continue;
                }
                if (!self::isPdfFile($tmpName)) {
                    $skipped++;
                    continue;
                }
                $content = file_get_contents($tmpName);
                $drive->uploadToInbox(basename((string) $files['name'][$i]), $content);
                $uploaded++;
            }
        }

        header('Location: /assessment/teacher/papers/inbox?uploaded=' . $uploaded . '&skipped=' . $skipped);
        exit;
    }

    /** Moves an Inbox file onto a specific paper's exam-paper or mark-scheme slot, replacing whatever was already there. */
    public static function attachInbox(string $itemId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();

        $paperId = (int) ($_POST['paper_id'] ?? 0);
        $paper = self::requireManageable($paperId, $user);
        if ($paper['type'] !== 'pdf') {
            http_response_code(422);
            echo 'Only PDF-type papers can have files attached.';
            exit;
        }

        $slot = ($_POST['slot'] ?? '') === 'markscheme' ? 'markscheme' : 'paper';
        $filename = $slot === 'markscheme' ? 'mark_scheme.pdf' : 'paper.pdf';
        $field = $slot === 'markscheme' ? 'mark_scheme_drive_item_id' : 'pdf_drive_item_id';

        $drive = new OneDriveService((int) $user['id']);
        $newItemId = $drive->attachInboxItem($itemId, $paperId, $filename);
        Paper::replacePdfFile($paperId, $field, $newItemId);

        header('Location: /assessment/teacher/papers/inbox?attached=1');
        exit;
    }

    /** Discards an unwanted Inbox upload - it was never attached to anything, so there's nothing else to clean up. */
    public static function deleteInbox(string $itemId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();

        $drive = new OneDriveService((int) $user['id']);
        $drive->deleteInboxItem($itemId);

        header('Location: /assessment/teacher/papers/inbox');
        exit;
    }

    public static function show(int $paperId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $paper = self::requireViewable($paperId, $user);
        $questions = Question::forPaper($paperId);
        $canManage = self::canManagePaper($user, $paper);
        $canDelete = $canManage && !Paper::hasSubmissions($paperId);
        $selfTest = TestAssignment::findSelfTest($paperId, (int) $user['id']);
        $selfTestSubmission = $selfTest ? Submission::findByAssignmentAndStudent((int) $selfTest['id'], (int) $user['id']) : null;
        require_once __DIR__ . '/../models/PaperGroup.php';
        $groups = PaperGroup::all();
        $paperGroup = $paper['group_id'] ? PaperGroup::find((int) $paper['group_id']) : null;
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
        $paper = self::requireViewable($paperId, $user);

        $questions = Question::forPaper($paperId);
        $maxTotal = $questions ? array_sum(array_column($questions, 'max_marks')) : (float) ($paper['max_marks'] ?? 0);

        $assignments = TestAssignment::forPaper($paperId);
        $classes = [];
        foreach ($assignments as $assignment) {
            $classes[(int) $assignment['class_id']] = $assignment['class_name'];
        }

        $classFilter = !empty($_GET['class_id']) ? (int) $_GET['class_id'] : null;

        $rows = [];
        foreach ($assignments as $assignment) {
            if ($classFilter !== null && (int) $assignment['class_id'] !== $classFilter) {
                continue;
            }
            foreach (Submission::forAssignment((int) $assignment['id']) as $submission) {
                $isMarked = in_array($submission['status'], ['marked', 'moderated'], true);
                $rows[] = [
                    'submission' => $submission,
                    'class_id' => (int) $assignment['class_id'],
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

    /** Edits the max marks value for a pdf-type paper (no per-question breakdown) - editable any time, e.g. before assigning, or if it was set wrong. */
    public static function updateMaxMarks(int $paperId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();
        self::requireManageable($paperId, $user);

        $maxMarks = !empty($_POST['max_marks']) ? (float) $_POST['max_marks'] : null;
        Paper::setMaxMarks($paperId, $maxMarks);

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
