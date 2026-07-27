<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/Crypto.php';

final class Mark
{
    /**
     * Teacher records/overwrites a score for a question on a submission -
     * or, if $questionId is null, an overall whole-paper mark (used for
     * pdf-type papers with no per-question breakdown, see
     * PaperController::store()/Paper::max_marks). Every write is preserved
     * (not updated in place) so the audit trail retains the full history of
     * primary marks and moderation adjustments. The comment is about a
     * specific student's work, so it's encrypted at rest like every other
     * piece of student content.
     */
    public static function record(int $submissionId, ?int $questionId, int $markerId, float $score, ?string $comment, string $type = 'primary'): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO marks (submission_id, question_id, marker_id, mark_type, score, comment_cipher)
             VALUES (:submission_id, :question_id, :marker_id, :mark_type, :score, :comment_cipher)'
        );
        $stmt->execute([
            'submission_id' => $submissionId,
            'question_id' => $questionId,
            'marker_id' => $markerId,
            'mark_type' => $type,
            'score' => $score,
            'comment_cipher' => Crypto::encrypt($comment),
        ]);
        $id = (int) Database::connection()->lastInsertId();

        AuditLog::record('submission', $submissionId, $markerId, "mark_{$type}", null, [
            'question_id' => $questionId,
            'score' => $score,
        ]);

        return $id;
    }

    /**
     * Latest mark per question for a submission, of a given type, keyed by
     * question_id - or by the string 'overall' for a whole-paper mark
     * (question_id IS NULL; MySQL's GROUP BY treats all NULLs as one group,
     * so "the latest overall mark" falls out of the same query for free).
     */
    public static function latestForSubmission(int $submissionId, string $type = 'primary'): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT m.* FROM marks m
             INNER JOIN (
                 SELECT question_id, MAX(id) AS max_id FROM marks
                 WHERE submission_id = :submission_id AND mark_type = :mark_type
                 GROUP BY question_id
             ) latest ON latest.max_id = m.id
             WHERE m.submission_id = :submission_id2'
        );
        $stmt->execute(['submission_id' => $submissionId, 'mark_type' => $type, 'submission_id2' => $submissionId]);
        $rows = $stmt->fetchAll();
        $byQuestion = [];
        foreach ($rows as $row) {
            $key = $row['question_id'] !== null ? (int) $row['question_id'] : 'overall';
            $byQuestion[$key] = self::withDecryptedComment($row);
        }
        return $byQuestion;
    }

    public static function history(int $submissionId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT m.*, u.display_name_cipher AS marker_name_cipher FROM marks m
             INNER JOIN users u ON u.id = m.marker_id
             WHERE m.submission_id = :submission_id ORDER BY m.created_at ASC'
        );
        $stmt->execute(['submission_id' => $submissionId]);
        return array_map(static function (array $row): array {
            $row = self::withDecryptedComment($row);
            $row['marker_name'] = Crypto::decrypt($row['marker_name_cipher']);
            unset($row['marker_name_cipher']);
            return $row;
        }, $stmt->fetchAll());
    }

    private static function withDecryptedComment(array $row): array
    {
        $row['comment'] = Crypto::decrypt($row['comment_cipher']);
        unset($row['comment_cipher']);
        return $row;
    }

    public static function totalScore(int $submissionId, string $type = 'primary'): float
    {
        $marks = self::latestForSubmission($submissionId, $type);
        return array_sum(array_column($marks, 'score'));
    }
}
