<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../models/Paper.php';
require_once __DIR__ . '/../models/Question.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../services/OneDriveService.php';

/**
 * Paper/question bank management: digital question authoring, bulk CSV
 * import, and PDF exam paper + mark scheme uploads to OneDrive (SRS 5).
 */
final class PaperController
{
    public static function index(): void
    {
        $user = AuthController::requireRole([User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER]);
        $papers = Paper::byCreator((int) $user['id']);
        require __DIR__ . '/../views/teacher/papers_index.php';
    }

    public static function createForm(): void
    {
        AuthController::requireRole([User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER]);
        require __DIR__ . '/../views/teacher/paper_create.php';
    }

    public static function store(): void
    {
        $user = AuthController::requireRole([User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER]);
        AuthController::verifyCsrf();

        $type = ($_POST['type'] ?? 'digital') === 'pdf' ? 'pdf' : 'digital';

        $paperId = Paper::create([
            'title' => trim((string) ($_POST['title'] ?? 'Untitled paper')),
            'subject' => trim((string) ($_POST['subject'] ?? '')) ?: null,
            'type' => $type,
            'created_by' => $user['id'],
            'self_marking_enabled' => !empty($_POST['self_marking_enabled']),
            'duration_minutes' => !empty($_POST['duration_minutes']) ? (int) $_POST['duration_minutes'] : null,
        ]);

        if ($type === 'pdf' && !empty($_FILES['paper_pdf']['tmp_name'])) {
            self::attachPdfUploads($paperId, $user);
        }

        header('Location: /assessment/teacher/papers/' . $paperId);
        exit;
    }

    private static function attachPdfUploads(int $paperId, array $user): void
    {
        $drive = new OneDriveService((int) $user['id']);

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
        $user = AuthController::requireRole([User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER]);
        $paper = Paper::find($paperId);
        if (!$paper || (int) $paper['created_by'] !== (int) $user['id']) {
            http_response_code(404);
            exit;
        }
        $questions = Question::forPaper($paperId);
        require __DIR__ . '/../views/teacher/paper_show.php';
    }

    public static function addQuestion(int $paperId): void
    {
        $user = AuthController::requireRole([User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER]);
        AuthController::verifyCsrf();

        $paper = Paper::find($paperId);
        if (!$paper || (int) $paper['created_by'] !== (int) $user['id']) {
            http_response_code(404);
            exit;
        }

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
        $user = AuthController::requireRole([User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER]);
        AuthController::verifyCsrf();

        $paper = Paper::find($paperId);
        if (!$paper || (int) $paper['created_by'] !== (int) $user['id']) {
            http_response_code(404);
            exit;
        }

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
        $user = AuthController::requireRole([User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER]);
        AuthController::verifyCsrf();

        $paper = Paper::find($paperId);
        if (!$paper || (int) $paper['created_by'] !== (int) $user['id']) {
            http_response_code(404);
            exit;
        }
        Paper::publish($paperId);
        header('Location: /assessment/teacher/papers/' . $paperId);
        exit;
    }
}
