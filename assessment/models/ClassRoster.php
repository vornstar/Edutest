<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/User.php';

final class ClassRoster
{
    public static function create(string $name, ?string $subject, int $ownerTeacherId, ?string $teamsClassId = null): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO classes (teams_class_id, name, subject, owner_teacher_id) VALUES (:teams_class_id, :name, :subject, :owner_teacher_id)'
        );
        $stmt->execute([
            'teams_class_id' => $teamsClassId,
            'name' => $name,
            'subject' => $subject,
            'owner_teacher_id' => $ownerTeacherId,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM classes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByTeamsId(string $teamsClassId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM classes WHERE teams_class_id = :teams_class_id');
        $stmt->execute(['teams_class_id' => $teamsClassId]);
        return $stmt->fetch() ?: null;
    }

    public static function forTeacher(int $teacherId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM classes WHERE owner_teacher_id = :teacher_id ORDER BY name');
        $stmt->execute(['teacher_id' => $teacherId]);
        return $stmt->fetchAll();
    }

    /** Every class (any teacher) that's actually linked to a Teams class - used by the Admin > OneDrive setup lookup. */
    public static function allTeamsLinked(): array
    {
        $stmt = Database::connection()->query(
            'SELECT * FROM classes WHERE teams_class_id IS NOT NULL ORDER BY name'
        );
        return $stmt->fetchAll();
    }

    public static function markSynced(int $classId): void
    {
        $stmt = Database::connection()->prepare('UPDATE classes SET last_synced_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $classId]);
    }

    /**
     * Upsert a student's enrollment. Used by the Teams roster sync job so
     * students are automatically linked to their class without manual entry.
     */
    public static function enroll(int $classId, int $userId, string $roleInClass = 'student'): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO class_enrollments (class_id, user_id, role_in_class) VALUES (:class_id, :user_id, :role_in_class)
             ON DUPLICATE KEY UPDATE role_in_class = VALUES(role_in_class)'
        );
        $stmt->execute(['class_id' => $classId, 'user_id' => $userId, 'role_in_class' => $roleInClass]);
    }

    public static function removeEnrollment(int $classId, int $userId): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM class_enrollments WHERE class_id = :class_id AND user_id = :user_id');
        $stmt->execute(['class_id' => $classId, 'user_id' => $userId]);
    }

    /** display_name is encrypted (see User::hydrate) so it can't be sorted in SQL - decrypted then re-sorted by surname here instead. */
    public static function students(int $classId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.* FROM users u
             INNER JOIN class_enrollments ce ON ce.user_id = u.id
             WHERE ce.class_id = :class_id AND ce.role_in_class = "student"'
        );
        $stmt->execute(['class_id' => $classId]);
        $students = array_map([User::class, 'hydrate'], $stmt->fetchAll());
        usort($students, [User::class, 'compareBySurname']);
        return $students;
    }

    public static function isMember(int $classId, int $userId): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM class_enrollments WHERE class_id = :class_id AND user_id = :user_id');
        $stmt->execute(['class_id' => $classId, 'user_id' => $userId]);
        return (bool) $stmt->fetchColumn();
    }
}
