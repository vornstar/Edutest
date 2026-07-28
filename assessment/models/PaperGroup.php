<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * A teacher's own way of bundling related papers together (e.g. every test
 * for one topic/course) - distinct from Subject, a school-wide taxonomy
 * driving visibility (see schema.sql). Any teacher-portal user can create
 * one, not just admins - see PaperGroupController.
 */
final class PaperGroup
{
    public static function all(): array
    {
        return Database::connection()->query('SELECT * FROM paper_groups ORDER BY name')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM paper_groups WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(string $name, int $createdBy): int
    {
        $stmt = Database::connection()->prepare('INSERT INTO paper_groups (name, created_by) VALUES (:name, :created_by)');
        $stmt->execute(['name' => $name, 'created_by' => $createdBy]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function rename(int $id, string $name): void
    {
        $stmt = Database::connection()->prepare('UPDATE paper_groups SET name = :name WHERE id = :id');
        $stmt->execute(['name' => $name, 'id' => $id]);
    }

    /** Papers in the group aren't deleted - group_id just goes back to NULL (ON DELETE SET NULL). */
    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM paper_groups WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
