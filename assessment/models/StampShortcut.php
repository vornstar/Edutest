<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * A marker's own keyboard shortcut per stamp (built-in or custom, matched
 * by label - see partials/stamp_toolbar.php) OR per annotation tool (Pen,
 * Highlighter, Text, Circle, Delete, matched by data-tool value) - per-user,
 * like custom stamps themselves. Kept out of custom_stamps entirely since
 * it needs to cover the built-in stamps and tools too, neither of which
 * are rows there.
 */
final class StampShortcut
{
    public const TARGET_TOOL = 'tool';
    public const TARGET_STAMP = 'stamp';

    /** @return array{tool: array<string,string>, stamp: array<string,string>} target_type => label => shortcut_key, lowercased */
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare('SELECT target_type, stamp_label, shortcut_key FROM stamp_shortcuts WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $map = [self::TARGET_TOOL => [], self::TARGET_STAMP => []];
        foreach ($stmt->fetchAll() as $row) {
            $type = $row['target_type'] === self::TARGET_TOOL ? self::TARGET_TOOL : self::TARGET_STAMP;
            $map[$type][$row['stamp_label']] = strtolower($row['shortcut_key']);
        }
        return $map;
    }

    /**
     * Assigns $key to $label (of the given $targetType) for this user, first
     * clearing any existing row for either that exact target or that key -
     * a single key can only ever trigger one thing (tool or stamp), so
     * re-assigning it here silently takes it away from whatever it was on
     * before, rather than erroring on the table's own unique constraint.
     */
    public static function set(int $userId, string $targetType, string $label, string $key): void
    {
        $targetType = $targetType === self::TARGET_TOOL ? self::TARGET_TOOL : self::TARGET_STAMP;
        $key = strtolower(substr($key, 0, 1));
        if ($key === '') {
            self::remove($userId, $targetType, $label);
            return;
        }

        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM stamp_shortcuts WHERE user_id = :user_id AND ((target_type = :target_type AND stamp_label = :label) OR shortcut_key = :key)')
            ->execute(['user_id' => $userId, 'target_type' => $targetType, 'label' => $label, 'key' => $key]);
        $pdo->prepare('INSERT INTO stamp_shortcuts (user_id, target_type, stamp_label, shortcut_key) VALUES (:user_id, :target_type, :label, :key)')
            ->execute(['user_id' => $userId, 'target_type' => $targetType, 'label' => $label, 'key' => $key]);
    }

    public static function remove(int $userId, string $targetType, string $label): void
    {
        $targetType = $targetType === self::TARGET_TOOL ? self::TARGET_TOOL : self::TARGET_STAMP;
        $stmt = Database::connection()->prepare('DELETE FROM stamp_shortcuts WHERE user_id = :user_id AND target_type = :target_type AND stamp_label = :label');
        $stmt->execute(['user_id' => $userId, 'target_type' => $targetType, 'label' => $label]);
    }
}
