<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class User
{
    public const ROLE_STUDENT = 1;
    public const ROLE_TEACHER = 2;
    public const ROLE_SUBJECT_LEADER = 3;
    public const ROLE_DATA = 4;
    public const ROLE_ADMIN = 5;

    public const ROLE_NAMES = [
        self::ROLE_STUDENT => 'student',
        self::ROLE_TEACHER => 'teacher',
        self::ROLE_SUBJECT_LEADER => 'subject_leader',
        self::ROLE_DATA => 'data',
        self::ROLE_ADMIN => 'admin',
    ];

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function all(int $limit = 200, int $offset = 0): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users ORDER BY display_name LIMIT :limit OFFSET :offset');
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function setRole(int $userId, int $roleId, int $actingAdminId): void
    {
        if (!array_key_exists($roleId, self::ROLE_NAMES)) {
            throw new InvalidArgumentException('Unknown role id.');
        }
        $pdo = Database::connection();
        $before = self::find($userId);
        $stmt = $pdo->prepare('UPDATE users SET role_id = :role_id WHERE id = :id');
        $stmt->execute(['role_id' => $roleId, 'id' => $userId]);

        require_once __DIR__ . '/AuditLog.php';
        AuditLog::record('user', $userId, $actingAdminId, 'role_change',
            ['role_id' => $before['role_id'] ?? null],
            ['role_id' => $roleId]
        );
    }

    public static function roleName(int $roleId): string
    {
        return self::ROLE_NAMES[$roleId] ?? 'student';
    }
}
