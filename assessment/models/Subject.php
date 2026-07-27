<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * The canonical subject list backing Admin > Subjects and every subject
 * dropdown (teacher assignment, paper creation) - see schema.sql for why
 * this isn't a foreign key against users/papers.
 */
final class Subject
{
    public static function all(): array
    {
        return Database::connection()->query('SELECT * FROM subjects ORDER BY name')->fetchAll();
    }

    public static function create(string $name): int
    {
        $stmt = Database::connection()->prepare('INSERT INTO subjects (name) VALUES (:name)');
        $stmt->execute(['name' => $name]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function rename(int $id, string $name): void
    {
        $stmt = Database::connection()->prepare('UPDATE subjects SET name = :name WHERE id = :id');
        $stmt->execute(['name' => $name, 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM subjects WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
