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
