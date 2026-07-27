<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

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

    public static function autosaveAnswer(int $submissionId, int $questionId, string $answerText): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO answers (submission_id, question_id, answer_text) VALUES (:submission_id, :question_id, :answer_text)
             ON DUPLICATE KEY UPDATE answer_text = VALUES(answer_text), autosaved_at = NOW()'
        );
        $stmt->execute(['submission_id' => $submissionId, 'question_id' => $questionId, 'answer_text' => $answerText]);
    }

    public static function answers(int $submissionId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM answers WHERE submission_id = :submission_id');
        $stmt->execute(['submission_id' => $submissionId]);
        $rows = $stmt->fetchAll();
        $byQuestion = [];
        foreach ($rows as $row) {
            $byQuestion[(int) $row['question_id']] = $row;
        }
        return $byQuestion;
    }

    public static function submit(int $submissionId): void
    {
        $stmt = Database::connection()->prepare("UPDATE submissions SET status = 'submitted', submitted_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $submissionId]);
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
            'INSERT INTO self_marks (submission_id, question_id, student_mark, reflection_comment)
             VALUES (:submission_id, :question_id, :student_mark, :reflection)
             ON DUPLICATE KEY UPDATE student_mark = VALUES(student_mark), reflection_comment = VALUES(reflection_comment)'
        );
        $stmt->execute([
            'submission_id' => $submissionId,
            'question_id' => $questionId,
            'student_mark' => $studentMark,
            'reflection' => $reflection,
        ]);
    }

    public static function selfMarks(int $submissionId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM self_marks WHERE submission_id = :submission_id');
        $stmt->execute(['submission_id' => $submissionId]);
        $rows = $stmt->fetchAll();
        $byQuestion = [];
        foreach ($rows as $row) {
            $byQuestion[(int) $row['question_id']] = $row;
        }
        return $byQuestion;
    }

    /** Marks the submission as pending teacher moderation once self-marking is complete. */
    public static function completeSelfMarking(int $submissionId): void
    {
        self::setStatus($submissionId, 'pending_moderation');
    }

    public static function forAssignment(int $assignmentId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT s.*, u.display_name AS student_name FROM submissions s
             INNER JOIN users u ON u.id = s.student_id
             WHERE s.assignment_id = :assignment_id ORDER BY u.display_name'
        );
        $stmt->execute(['assignment_id' => $assignmentId]);
        return $stmt->fetchAll();
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
