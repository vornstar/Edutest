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
    /**
     * A marker's own layer has no "start over" concept (that's a student-only
     * thing - see submissions.annotation_version), so it always saves at one
     * of these two FIXED versions rather than an incrementing one - EXCEPT
     * they must be two DIFFERENT fixed versions, not both 1: the same person
     * can end up as both a submission's primary marker and (separately)
     * assigned as its moderator, and if primary and moderation both saved at
     * version 1, their moderation-time marks would land on the exact same
     * (submission, page, marker_id, version) row as their own earlier
     * primary marking, silently overwriting it - the same failure mode as
     * the self-test case (see MarkingController::saveAnnotation()), just
     * triggered by the same PERSON occupying two roles instead of two
     * IDENTITIES colliding.
     */
    public const VERSION_PRIMARY = 1;
    public const VERSION_MODERATION = 2;

    public static function save(int $submissionId, int $pageNumber, int $markerId, int $version, array $fabricJson): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO annotations (submission_id, page_number, marker_id, version, data_cipher)
             VALUES (:submission_id, :page_number, :marker_id, :version, :data_cipher)
             ON DUPLICATE KEY UPDATE data_cipher = VALUES(data_cipher), updated_at = NOW()'
        );
        $stmt->execute([
            'submission_id' => $submissionId,
            'page_number' => $pageNumber,
            'marker_id' => $markerId,
            'version' => $version,
            'data_cipher' => Crypto::encrypt(json_encode($fabricJson)),
        ]);
    }

    /**
     * Only the LATEST version of each (page, marker) - the normal "what does
     * this page look like right now" query used everywhere except the
     * teacher's version-history picker (see forMarkerVersion() below).
     * @return array each row's 'data_json' (the Fabric.js JSON string) decrypted transparently
     */
    public static function forSubmission(int $submissionId): array
    {
        // Real (non-emulated) prepared statements reject the same named
        // placeholder appearing twice in one query - :submission_id needs a
        // distinct name at each occurrence, both bound to the same value.
        $stmt = Database::connection()->prepare(
            'SELECT a.* FROM annotations a
             INNER JOIN (
                 SELECT page_number, marker_id, MAX(version) AS max_version
                 FROM annotations WHERE submission_id = :submission_id_1
                 GROUP BY page_number, marker_id
             ) latest ON latest.page_number = a.page_number AND latest.marker_id = a.marker_id AND latest.max_version = a.version
             WHERE a.submission_id = :submission_id_2
             ORDER BY a.page_number, a.marker_id'
        );
        $stmt->execute(['submission_id_1' => $submissionId, 'submission_id_2' => $submissionId]);
        return self::decryptAll($stmt->fetchAll());
    }

    /** Every page of one specific marker+version - used to render a chosen historical version in the teacher's marking view. */
    public static function forMarkerVersion(int $submissionId, int $markerId, int $version): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM annotations WHERE submission_id = :submission_id AND marker_id = :marker_id AND version = :version ORDER BY page_number'
        );
        $stmt->execute(['submission_id' => $submissionId, 'marker_id' => $markerId, 'version' => $version]);
        return self::decryptAll($stmt->fetchAll());
    }

    /** Every version number a marker has ever saved under, oldest first, with when it was started/last touched - powers the teacher's version picker. */
    public static function versionsForMarker(int $submissionId, int $markerId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT version, MIN(created_at) AS started_at, MAX(updated_at) AS last_saved_at
             FROM annotations WHERE submission_id = :submission_id AND marker_id = :marker_id
             GROUP BY version ORDER BY version'
        );
        $stmt->execute(['submission_id' => $submissionId, 'marker_id' => $markerId]);
        return $stmt->fetchAll();
    }

    private static function decryptAll(array $rows): array
    {
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
