<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class TestAssignment
{
    public static function create(int $paperId, ?int $classId, int $assignedBy, ?string $dueAt, bool $syncToTeams, bool $selfMarkingEnabled = false): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO test_assignments (paper_id, class_id, assigned_by, due_at, sync_to_teams, self_marking_enabled)
             VALUES (:paper_id, :class_id, :assigned_by, :due_at, :sync_to_teams, :self_marking_enabled)'
        );
        $stmt->execute([
            'paper_id' => $paperId,
            'class_id' => $classId,
            'assigned_by' => $assignedBy,
            'due_at' => $dueAt,
            'sync_to_teams' => $syncToTeams ? 1 : 0,
            'self_marking_enabled' => $selfMarkingEnabled ? 1 : 0,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM test_assignments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function setTeamsAssignmentId(int $id, string $teamsAssignmentId): void
    {
        $stmt = Database::connection()->prepare('UPDATE test_assignments SET teams_assignment_id = :teams_id WHERE id = :id');
        $stmt->execute(['teams_id' => $teamsAssignmentId, 'id' => $id]);
    }

    public static function setStatus(int $id, string $status): void
    {
        $stmt = Database::connection()->prepare('UPDATE test_assignments SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    /**
     * Self-marking is per-assignment (not per-paper) specifically so a
     * teacher can flip it on only once everyone's finished - e.g. leave it
     * off while the class is still sitting the test, then enable it so
     * early finishers can't see the mark scheme while others are still
     * working.
     */
    public static function setSelfMarking(int $id, bool $enabled): void
    {
        $stmt = Database::connection()->prepare('UPDATE test_assignments SET self_marking_enabled = :enabled WHERE id = :id');
        $stmt->execute(['enabled' => $enabled ? 1 : 0, 'id' => $id]);
    }

    /** Every real (non-self-test) assignment of this paper, across every class it's been assigned to - used by the Results page. */
    public static function forPaper(int $paperId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT a.*, c.name AS class_name FROM test_assignments a
             INNER JOIN classes c ON c.id = a.class_id
             WHERE a.paper_id = :paper_id ORDER BY a.created_at DESC'
        );
        $stmt->execute(['paper_id' => $paperId]);
        return $stmt->fetchAll();
    }

    /** Every real (non-self-test) assignment this teacher-portal user has assigned - used by the "Open tests" page. */
    public static function forAssignedBy(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT a.*, p.title AS paper_title, c.name AS class_name FROM test_assignments a
             INNER JOIN papers p ON p.id = a.paper_id
             INNER JOIN classes c ON c.id = a.class_id
             WHERE a.assigned_by = :assigned_by AND a.class_id IS NOT NULL
             ORDER BY a.closed_at IS NOT NULL, a.due_at IS NULL, a.due_at ASC'
        );
        $stmt->execute(['assigned_by' => $userId]);
        return $stmt->fetchAll();
    }

    /** Ends a test window early - blocks further student work (see TestController::authorizeSubmissionOwner/take), independent of due_at. */
    public static function close(int $id): void
    {
        $stmt = Database::connection()->prepare('UPDATE test_assignments SET closed_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public static function reopen(int $id): void
    {
        $stmt = Database::connection()->prepare('UPDATE test_assignments SET closed_at = NULL WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public static function forStudent(int $studentId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT a.*, p.title, p.type FROM test_assignments a
             INNER JOIN papers p ON p.id = a.paper_id
             INNER JOIN class_enrollments ce ON ce.class_id = a.class_id
             WHERE ce.user_id = :student_id AND ce.role_in_class = "student"
             ORDER BY a.due_at IS NULL, a.due_at ASC'
        );
        $stmt->execute(['student_id' => $studentId]);
        return $stmt->fetchAll();
    }

    public static function forClass(int $classId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT a.*, p.title, p.type FROM test_assignments a
             INNER JOIN papers p ON p.id = a.paper_id
             WHERE a.class_id = :class_id ORDER BY a.created_at DESC'
        );
        $stmt->execute(['class_id' => $classId]);
        return $stmt->fetchAll();
    }

    /**
     * A "self-test" is a real assignment/submission pair a teacher-portal
     * user creates against their own account, purely so they can try the
     * whole real student flow (typing, autosave, submitting) - and then
     * mark/moderate it - before any real student sees the paper. Marked by
     * class_id IS NULL (a real assignment always has a class), scoped to
     * whoever created it.
     */
    public static function findSelfTest(int $paperId, int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM test_assignments WHERE paper_id = :paper_id AND class_id IS NULL AND assigned_by = :assigned_by LIMIT 1'
        );
        $stmt->execute(['paper_id' => $paperId, 'assigned_by' => $userId]);
        return $stmt->fetch() ?: null;
    }

    public static function isSelfTest(array $assignment): bool
    {
        return $assignment['class_id'] === null;
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM test_assignments WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
