<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * Vector overlay annotations (freehand ink, text boxes, highlighters) drawn
 * on top of a PDF page or scanned script, captured as Fabric.js/PDF.js JSON.
 * Kept separate per (submission, page, marker) so primary and moderation
 * annotations don't overwrite each other.
 */
final class Annotation
{
    public static function save(int $submissionId, int $pageNumber, int $markerId, array $fabricJson): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO annotations (submission_id, page_number, marker_id, data_json)
             VALUES (:submission_id, :page_number, :marker_id, :data_json)
             ON DUPLICATE KEY UPDATE data_json = VALUES(data_json), updated_at = NOW()'
        );
        $stmt->execute([
            'submission_id' => $submissionId,
            'page_number' => $pageNumber,
            'marker_id' => $markerId,
            'data_json' => json_encode($fabricJson),
        ]);
    }

    public static function forSubmission(int $submissionId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM annotations WHERE submission_id = :submission_id ORDER BY page_number, marker_id');
        $stmt->execute(['submission_id' => $submissionId]);
        return $stmt->fetchAll();
    }

    public static function markFlattened(int $submissionId, int $pageNumber, int $markerId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE annotations SET flattened = 1 WHERE submission_id = :submission_id AND page_number = :page_number AND marker_id = :marker_id'
        );
        $stmt->execute(['submission_id' => $submissionId, 'page_number' => $pageNumber, 'marker_id' => $markerId]);
    }
}
