<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * A marker's own quick-stamp shortcuts (SRS marking) - shown alongside the
 * built-in Tick/Cross/SEEN/NE/LC/BOD stamps in the marking toolbar. Not
 * encrypted: a stamp label is a short reusable abbreviation the marker
 * defines themselves, not student data.
 */
final class CustomStamp
{
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM custom_stamps WHERE user_id = :user_id ORDER BY created_at');
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public static function create(int $userId, string $label): int
    {
        $stmt = Database::connection()->prepare('INSERT INTO custom_stamps (user_id, label) VALUES (:user_id, :label)');
        $stmt->execute(['user_id' => $userId, 'label' => $label]);
        return (int) Database::connection()->lastInsertId();
    }

    /** Ownership is enforced in the query itself, not a separate check - deleting someone else's stamp id is simply a no-op. */
    public static function delete(int $id, int $userId): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM custom_stamps WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
    }
}
