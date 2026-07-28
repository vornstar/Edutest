<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Crypto.php';

/**
 * Digital question bank entries. mark_scheme / model_answer are encrypted
 * with AES-256-GCM before being written to `mark_scheme_cipher` /
 * `model_answer_cipher` and only ever decrypted in memory when explicitly
 * requested (teacher marking view, or student self-marking once released).
 */
final class Question
{
    public static function create(array $data): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO questions (paper_id, section, order_index, type, question_text, options_json, correct_option, max_marks, mark_scheme_cipher, model_answer_cipher)
             VALUES (:paper_id, :section, :order_index, :type, :question_text, :options_json, :correct_option, :max_marks, :mark_scheme_cipher, :model_answer_cipher)'
        );
        $stmt->execute([
            'paper_id' => $data['paper_id'],
            'section' => $data['section'] ?? null,
            'order_index' => $data['order_index'] ?? 0,
            'type' => $data['type'],
            'question_text' => $data['question_text'],
            'options_json' => isset($data['options']) ? json_encode($data['options']) : null,
            'correct_option' => $data['correct_option'] ?? null,
            'max_marks' => $data['max_marks'] ?? 1.0,
            'mark_scheme_cipher' => Crypto::encrypt($data['mark_scheme'] ?? null),
            'model_answer_cipher' => Crypto::encrypt($data['model_answer'] ?? null),
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function forPaper(int $paperId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM questions WHERE paper_id = :paper_id ORDER BY order_index ASC, id ASC');
        $stmt->execute(['paper_id' => $paperId]);
        return $stmt->fetchAll();
    }

    /** Every digital paper's total available marks (SUM of its questions' max_marks), keyed by paper_id - one query for the whole papers list rather than one per paper. Papers with no questions yet, or pdf-type papers (which have no rows here at all), simply have no entry. */
    public static function totalMarksByPaper(): array
    {
        $stmt = Database::connection()->query('SELECT paper_id, SUM(max_marks) AS total FROM questions GROUP BY paper_id');
        $totals = [];
        foreach ($stmt->fetchAll() as $row) {
            $totals[(int) $row['paper_id']] = (float) $row['total'];
        }
        return $totals;
    }

    /** Edits one existing question's max marks - the only per-question field editable after creation, so a digital paper's total (SUM of these, see totalMarksByPaper) can be corrected without recreating the question. */
    public static function setMaxMarks(int $questionId, float $maxMarks): void
    {
        $stmt = Database::connection()->prepare('UPDATE questions SET max_marks = :max_marks WHERE id = :id');
        $stmt->execute(['max_marks' => $maxMarks, 'id' => $questionId]);
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM questions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Decrypts the mark scheme text for authorized display only. */
    public static function decryptedMarkScheme(array $question): ?string
    {
        return Crypto::decrypt($question['mark_scheme_cipher']);
    }

    public static function decryptedModelAnswer(array $question): ?string
    {
        return Crypto::decrypt($question['model_answer_cipher']);
    }
}
