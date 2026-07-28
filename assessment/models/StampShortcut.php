<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * A marker's own keyboard shortcut per stamp (built-in or custom, matched
 * by label - see partials/stamp_toolbar.php) - per-user, like custom
 * stamps themselves. Kept out of custom_stamps entirely since it needs to
 * cover the built-in stamps too, which aren't rows there.
 */
final class StampShortcut
{
    /** @return array<string,string> stamp_label => shortcut_key, lowercased */
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare('SELECT stamp_label, shortcut_key FROM stamp_shortcuts WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['stamp_label']] = strtolower($row['shortcut_key']);
        }
        return $map;
    }

    /**
     * Assigns $key to $stampLabel for this user, first clearing any
     * existing row for either that label or that key - a single key can
     * only ever trigger one stamp, so re-assigning it here silently takes
     * it away from whatever it was on before, rather than erroring on the
     * table's own unique constraint.
     */
    public static function set(int $userId, string $stampLabel, string $key): void
    {
        $key = strtolower(substr($key, 0, 1));
        if ($key === '') {
            self::remove($userId, $stampLabel);
            return;
        }

        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM stamp_shortcuts WHERE user_id = :user_id AND (stamp_label = :label OR shortcut_key = :key)')
            ->execute(['user_id' => $userId, 'label' => $stampLabel, 'key' => $key]);
        $pdo->prepare('INSERT INTO stamp_shortcuts (user_id, stamp_label, shortcut_key) VALUES (:user_id, :label, :key)')
            ->execute(['user_id' => $userId, 'label' => $stampLabel, 'key' => $key]);
    }

    public static function remove(int $userId, string $stampLabel): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM stamp_shortcuts WHERE user_id = :user_id AND stamp_label = :label');
        $stmt->execute(['user_id' => $userId, 'label' => $stampLabel]);
    }
}
