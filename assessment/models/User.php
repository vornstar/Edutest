<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class User
{
    public const ROLE_STUDENT = 'student';
    public const ROLE_TEACHER = 'teacher';
    public const ROLE_SUBJECT_LEADER = 'subject_leader';
    public const ROLE_DATA = 'data';
    public const ROLE_ADMIN = 'admin';

    public const ROLES = [
        self::ROLE_STUDENT,
        self::ROLE_TEACHER,
        self::ROLE_SUBJECT_LEADER,
        self::ROLE_DATA,
        self::ROLE_ADMIN,
    ];

    /**
     * Roles that can access the Teacher portal (create/mark papers, manage
     * classes, moderate). Per the SRS, Admin has full system access - it
     * isn't a separate silo from Teacher/Subject Leader, it's a superset -
     * so real staff who are also admins (very common in a small school)
     * aren't locked out of the Teacher portal just for being admin too.
     */
    public const TEACHER_PORTAL_ROLES = [
        self::ROLE_TEACHER,
        self::ROLE_SUBJECT_LEADER,
        self::ROLE_ADMIN,
    ];

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function findBySiteUserId(int $siteUserId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE site_user_id = :site_user_id');
        $stmt->execute(['site_user_id' => $siteUserId]);
        return $stmt->fetch() ?: null;
    }

    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute(['email' => strtolower($email)]);
        return $stmt->fetch() ?: null;
    }

    public static function all(int $limit = 500, int $offset = 0): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users ORDER BY display_name LIMIT :limit OFFSET :offset');
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Called on every authenticated request. Reconciles the assessment
     * platform's local identity with the site-wide session that the root
     * auth_handler.php already established:
     *
     *  - Matched by site_user_id: this person has used the assessment
     *    platform before; just refresh their name/email.
     *  - Matched by email only: an Admin pre-provisioned this person (see
     *    addByEmail()) before their first assessment-platform visit; link
     *    the site_user_id now, keeping whatever role the Admin assigned.
     *  - No match: brand new identity, created as 'student' - the safe
     *    default. Nothing about this sync path ever elevates a role.
     */
    public static function syncFromSession(int $siteUserId, string $email, string $displayName): array
    {
        $email = strtolower(trim($email));
        $pdo = Database::connection();

        $existing = self::findBySiteUserId($siteUserId) ?? self::findByEmail($email);

        if ($existing) {
            $stmt = $pdo->prepare(
                'UPDATE users SET site_user_id = :site_user_id, email = :email, display_name = :display_name WHERE id = :id'
            );
            $stmt->execute([
                'site_user_id' => $siteUserId,
                'email' => $email,
                'display_name' => $displayName,
                'id' => $existing['id'],
            ]);
            $existing['site_user_id'] = $siteUserId;
            $existing['email'] = $email;
            $existing['display_name'] = $displayName;
            return $existing;
        }

        $insert = $pdo->prepare(
            "INSERT INTO users (site_user_id, email, display_name, role) VALUES (:site_user_id, :email, :display_name, 'student')"
        );
        $insert->execute([
            'site_user_id' => $siteUserId,
            'email' => $email,
            'display_name' => $displayName,
        ]);

        return self::find((int) $pdo->lastInsertId());
    }

    /**
     * Admin > Users "add user" action: pre-provisions a colleague from the
     * same tenant by email with a given role, before they've ever signed
     * in. Their first visit to the assessment platform links site_user_id
     * onto this row via syncFromSession() above, preserving the role set
     * here.
     */
    public static function addByEmail(string $email, string $displayName, string $role, int $actingAdminId): array
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Unknown role.');
        }
        $email = strtolower(trim($email));

        $existing = self::findByEmail($email);
        if ($existing) {
            return $existing;
        }

        $pdo = Database::connection();
        $insert = $pdo->prepare('INSERT INTO users (email, display_name, role) VALUES (:email, :display_name, :role)');
        $insert->execute([
            'email' => $email,
            'display_name' => $displayName !== '' ? $displayName : $email,
            'role' => $role,
        ]);
        $user = self::find((int) $pdo->lastInsertId());

        require_once __DIR__ . '/AuditLog.php';
        AuditLog::record('user', (int) $user['id'], $actingAdminId, 'created_by_admin', null, ['email' => $email, 'role' => $role]);

        return $user;
    }

    /**
     * Used by the Teams roster sync (TeamsService) to ensure a class member
     * has a local row to enroll, without ever granting them anything beyond
     * the default 'student' role. If they were already pre-provisioned by
     * an Admin (found by email) or have signed in before, that existing row
     * - and whatever role it holds - is reused untouched.
     */
    public static function provisionFromRoster(string $email, string $displayName): array
    {
        $existing = self::findByEmail($email);
        if ($existing) {
            return $existing;
        }

        $pdo = Database::connection();
        $insert = $pdo->prepare(
            "INSERT INTO users (email, display_name, role) VALUES (:email, :display_name, 'student')"
        );
        $insert->execute(['email' => strtolower(trim($email)), 'display_name' => $displayName]);
        return self::find((int) $pdo->lastInsertId());
    }

    public static function setRole(int $userId, string $role, int $actingAdminId): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Unknown role.');
        }
        $pdo = Database::connection();
        $before = self::find($userId);
        $stmt = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
        $stmt->execute(['role' => $role, 'id' => $userId]);

        require_once __DIR__ . '/AuditLog.php';
        AuditLog::record('user', $userId, $actingAdminId, 'role_change',
            ['role' => $before['role'] ?? null],
            ['role' => $role]
        );
    }

    public static function roleName(string $role): string
    {
        return in_array($role, self::ROLES, true) ? $role : self::ROLE_STUDENT;
    }
}
