<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Mark.php';
require_once __DIR__ . '/AuditLog.php';

final class Moderation
{
    public static function assign(int $submissionId, ?int $primaryMarkerId, int $secondaryMarkerId, string $mode, float $tolerance, int $assignedBy): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO moderation_assignments (submission_id, primary_marker_id, secondary_marker_id, mode, tolerance, assigned_by)
             VALUES (:submission_id, :primary_marker_id, :secondary_marker_id, :mode, :tolerance, :assigned_by)'
        );
        $stmt->execute([
            'submission_id' => $submissionId,
            'primary_marker_id' => $primaryMarkerId,
            'secondary_marker_id' => $secondaryMarkerId,
            'mode' => $mode,
            'tolerance' => $tolerance,
            'assigned_by' => $assignedBy,
        ]);
        $id = (int) Database::connection()->lastInsertId();

        AuditLog::record('submission', $submissionId, $assignedBy, 'moderation_assigned', null, [
            'secondary_marker_id' => $secondaryMarkerId,
            'mode' => $mode,
        ]);

        return $id;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM moderation_assignments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function forSecondaryMarker(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ma.*, s.assignment_id, u.display_name AS student_name FROM moderation_assignments ma
             INNER JOIN submissions s ON s.id = ma.submission_id
             INNER JOIN users u ON u.id = s.student_id
             WHERE ma.secondary_marker_id = :user_id ORDER BY ma.created_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Completes moderation: compares the secondary marker's total against the
     * primary marker's total for the submission and flags to the Subject
     * Leader if the variance exceeds the configured tolerance.
     */
    public static function complete(int $moderationId): array
    {
        $moderation = self::find($moderationId);
        if (!$moderation) {
            throw new InvalidArgumentException('Unknown moderation assignment.');
        }

        $primaryTotal = Mark::totalScore((int) $moderation['submission_id'], 'primary');
        $moderationTotal = Mark::totalScore((int) $moderation['submission_id'], 'moderation');
        $variance = round(abs($primaryTotal - $moderationTotal), 2);
        $exceeded = $variance > (float) $moderation['tolerance'];

        $stmt = Database::connection()->prepare(
            'UPDATE moderation_assignments SET variance = :variance, status = :status, completed_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'variance' => $variance,
            'status' => $exceeded ? 'flagged' : 'completed',
            'id' => $moderationId,
        ]);

        require_once __DIR__ . '/Submission.php';
        Submission::setStatus((int) $moderation['submission_id'], 'moderated');

        AuditLog::record('submission', (int) $moderation['submission_id'], (int) $moderation['secondary_marker_id'], 'moderation_completed', null, [
            'variance' => $variance,
            'exceeded_tolerance' => $exceeded,
        ]);

        return ['variance' => $variance, 'exceeded_tolerance' => $exceeded];
    }

    public static function flaggedForDepartment(): array
    {
        $stmt = Database::connection()->query(
            "SELECT ma.*, s.assignment_id FROM moderation_assignments ma
             INNER JOIN submissions s ON s.id = ma.submission_id
             WHERE ma.status = 'flagged' ORDER BY ma.completed_at DESC"
        );
        return $stmt->fetchAll();
    }
}
