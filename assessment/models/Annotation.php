<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Crypto.php';

/**
 * Vector overlay annotations (freehand ink, text boxes, highlighters) drawn
 * on top of a PDF page or scanned script, captured as Fabric.js/PDF.js JSON.
 * Kept separate per (submission, page, marker) so primary and moderation
 * annotations don't overwrite each other. This is the actual content of a
 * student's in-PDF answer, so it's encrypted at rest like answer_text.
 */
final class Annotation
{
    public static function save(int $submissionId, int $pageNumber, int $markerId, array $fabricJson): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO annotations (submission_id, page_number, marker_id, data_cipher)
             VALUES (:submission_id, :page_number, :marker_id, :data_cipher)
             ON DUPLICATE KEY UPDATE data_cipher = VALUES(data_cipher), updated_at = NOW()'
        );
        $stmt->execute([
            'submission_id' => $submissionId,
            'page_number' => $pageNumber,
            'marker_id' => $markerId,
            'data_cipher' => Crypto::encrypt(json_encode($fabricJson)),
        ]);
    }

    /** @return array each row's 'data_json' (the Fabric.js JSON string) decrypted transparently */
    public static function forSubmission(int $submissionId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM annotations WHERE submission_id = :submission_id ORDER BY page_number, marker_id');
        $stmt->execute(['submission_id' => $submissionId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['data_json'] = Crypto::decrypt($row['data_cipher']);
            unset($row['data_cipher']);
        }
        unset($row);
        return $rows;
    }

    public static function markFlattened(int $submissionId, int $pageNumber, int $markerId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE annotations SET flattened = 1 WHERE submission_id = :submission_id AND page_number = :page_number AND marker_id = :marker_id'
        );
        $stmt->execute(['submission_id' => $submissionId, 'page_number' => $pageNumber, 'marker_id' => $markerId]);
    }
}
