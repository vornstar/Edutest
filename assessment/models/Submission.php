<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Crypto.php';

final class Submission
{
    public static function startOrGet(int $assignmentId, int $studentId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM submissions WHERE assignment_id = :assignment_id AND student_id = :student_id');
        $stmt->execute(['assignment_id' => $assignmentId, 'student_id' => $studentId]);
        $existing = $stmt->fetch();
        if ($existing) {
            return $existing;
        }

        $insert = $pdo->prepare('INSERT INTO submissions (assignment_id, student_id) VALUES (:assignment_id, :student_id)');
        $insert->execute(['assignment_id' => $assignmentId, 'student_id' => $studentId]);
        $stmt->execute(['assignment_id' => $assignmentId, 'student_id' => $studentId]);
        return $stmt->fetch();
    }

    /** Read-only lookup - unlike startOrGet(), never creates a row, so safe to call from a GET request. */
    public static function findByAssignmentAndStudent(int $assignmentId, int $studentId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM submissions WHERE assignment_id = :assignment_id AND student_id = :student_id');
        $stmt->execute(['assignment_id' => $assignmentId, 'student_id' => $studentId]);
        return $stmt->fetch() ?: null;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM submissions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Student answer text is encrypted at rest (AES-256-GCM, see Crypto) - only ever decrypted in memory for authorized display. */
    public static function autosaveAnswer(int $submissionId, int $questionId, string $answerText): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO answers (submission_id, question_id, answer_cipher) VALUES (:submission_id, :question_id, :answer_cipher)
             ON DUPLICATE KEY UPDATE answer_cipher = VALUES(answer_cipher), autosaved_at = NOW()'
        );
        $stmt->execute(['submission_id' => $submissionId, 'question_id' => $questionId, 'answer_cipher' => Crypto::encrypt($answerText)]);
    }

    /** @return array<int,array> keyed by question_id, each row's 'answer_text' decrypted transparently */
    public static function answers(int $submissionId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM answers WHERE submission_id = :submission_id');
        $stmt->execute(['submission_id' => $submissionId]);
        $rows = $stmt->fetchAll();
        $byQuestion = [];
        foreach ($rows as $row) {
            $row['answer_text'] = Crypto::decrypt($row['answer_cipher']);
            unset($row['answer_cipher']);
            $byQuestion[(int) $row['question_id']] = $row;
        }
        return $byQuestion;
    }

    public static function submit(int $submissionId): void
    {
        $stmt = Database::connection()->prepare("UPDATE submissions SET status = 'submitted', submitted_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $submissionId]);
    }

    /**
     * "Start over" on in-PDF writing: bumps the student's active annotation
     * version so their next autosave starts a fresh, blank version instead
     * of overwriting the current one - the old version's rows stay in the
     * DB untouched for a teacher to look back at (see Annotation model).
     */
    public static function startNewAnnotationVersion(int $submissionId): int
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE submissions SET annotation_version = annotation_version + 1 WHERE id = :id')
            ->execute(['id' => $submissionId]);
        $stmt = $pdo->prepare('SELECT annotation_version FROM submissions WHERE id = :id');
        $stmt->execute(['id' => $submissionId]);
        return (int) $stmt->fetchColumn();
    }

    public static function attachScan(int $submissionId, string $driveItemId): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE submissions SET scan_drive_item_id = :drive_item_id, status = 'submitted', submitted_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['drive_item_id' => $driveItemId, 'id' => $submissionId]);
    }

    public static function setStatus(int $submissionId, string $status): void
    {
        $stmt = Database::connection()->prepare('UPDATE submissions SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $submissionId]);
    }

    public static function recordSelfMark(int $submissionId, int $questionId, float $studentMark, ?string $reflection): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO self_marks (submission_id, question_id, student_mark, reflection_cipher)
             VALUES (:submission_id, :question_id, :student_mark, :reflection_cipher)
             ON DUPLICATE KEY UPDATE student_mark = VALUES(student_mark), reflection_cipher = VALUES(reflection_cipher)'
        );
        $stmt->execute([
            'submission_id' => $submissionId,
            'question_id' => $questionId,
            'student_mark' => $studentMark,
            'reflection_cipher' => Crypto::encrypt($reflection),
        ]);
    }

    /** @return array<int,array> keyed by question_id, each row's 'reflection_comment' decrypted transparently */
    public static function selfMarks(int $submissionId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM self_marks WHERE submission_id = :submission_id');
        $stmt->execute(['submission_id' => $submissionId]);
        $rows = $stmt->fetchAll();
        $byQuestion = [];
        foreach ($rows as $row) {
            $row['reflection_comment'] = Crypto::decrypt($row['reflection_cipher']);
            unset($row['reflection_cipher']);
            $byQuestion[(int) $row['question_id']] = $row;
        }
        return $byQuestion;
    }

    /** Marks the submission as pending teacher moderation once self-marking is complete. */
    public static function completeSelfMarking(int $submissionId): void
    {
        self::setStatus($submissionId, 'pending_moderation');
    }

    /** Another submission still awaiting marking for the same paper (any class it's assigned to) - powers the "Next unmarked" button so a teacher can work through a batch without returning to the queue each time. */
    public static function nextUnmarked(int $currentSubmissionId, int $paperId): ?int
    {
        $stmt = Database::connection()->prepare(
            'SELECT s.id FROM submissions s
             INNER JOIN test_assignments a ON a.id = s.assignment_id
             WHERE a.paper_id = :paper_id AND s.id != :current_id AND s.status IN ("submitted", "pending_moderation")
             ORDER BY s.submitted_at ASC LIMIT 1'
        );
        $stmt->execute(['paper_id' => $paperId, 'current_id' => $currentSubmissionId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    /** student_name is encrypted (users.display_name_cipher) so it can't be sorted in SQL - decrypted then re-sorted alphabetically here instead. */
    public static function forAssignment(int $assignmentId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT s.*, u.display_name_cipher AS student_name_cipher FROM submissions s
             INNER JOIN users u ON u.id = s.student_id
             WHERE s.assignment_id = :assignment_id'
        );
        $stmt->execute(['assignment_id' => $assignmentId]);
        $rows = array_map(static function (array $row): array {
            $row['student_name'] = Crypto::decrypt($row['student_name_cipher']);
            unset($row['student_name_cipher']);
            return $row;
        }, $stmt->fetchAll());
        usort($rows, static fn($a, $b) => strcasecmp($a['student_name'], $b['student_name']));
        return $rows;
    }

    public static function forStudent(int $studentId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT s.*, p.title AS paper_title, p.type AS paper_type FROM submissions s
             INNER JOIN test_assignments a ON a.id = s.assignment_id
             INNER JOIN papers p ON p.id = a.paper_id
             WHERE s.student_id = :student_id ORDER BY s.started_at DESC'
        );
        $stmt->execute(['student_id' => $studentId]);
        return $stmt->fetchAll();
    }
}
