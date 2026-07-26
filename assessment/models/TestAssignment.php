<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class TestAssignment
{
    public static function create(int $paperId, ?int $classId, int $assignedBy, ?string $dueAt, bool $syncToTeams): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO test_assignments (paper_id, class_id, assigned_by, due_at, sync_to_teams)
             VALUES (:paper_id, :class_id, :assigned_by, :due_at, :sync_to_teams)'
        );
        $stmt->execute([
            'paper_id' => $paperId,
            'class_id' => $classId,
            'assigned_by' => $assignedBy,
            'due_at' => $dueAt,
            'sync_to_teams' => $syncToTeams ? 1 : 0,
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
}
