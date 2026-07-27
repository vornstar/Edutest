<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Crypto.php';

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

    private static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * email/display_name are encrypted at rest (see schema.sql) - this
     * decrypts a raw DB row back into the plain fields the rest of the app
     * already expects, so every caller keeps working against $row['email']
     * / $row['display_name'] unchanged. email_hash (a keyed HMAC, see
     * Crypto::searchHash) is only ever used for WHERE lookups, never
     * exposed past this point.
     */
    public static function hydrate(array $row): array
    {
        $row['email'] = Crypto::decrypt($row['email_cipher'] ?? null) ?? '';
        $row['display_name'] = Crypto::decrypt($row['display_name_cipher'] ?? null) ?? '';
        unset($row['email_cipher'], $row['display_name_cipher'], $row['email_hash']);
        return $row;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    public static function findBySiteUserId(int $siteUserId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE site_user_id = :site_user_id');
        $stmt->execute(['site_user_id' => $siteUserId]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    /** Looked up by email_hash (a keyed HMAC of the normalized email) - AES-GCM ciphertext can't be matched directly since its nonce makes every encryption of the same value different. */
    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE email_hash = :email_hash');
        $stmt->execute(['email_hash' => Crypto::searchHash(self::normalizeEmail($email))]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    /**
     * display_name is encrypted, so it can't be sorted in SQL - fetches
     * are bounded by $limit/$offset at the row level as before, then
     * decrypted and re-sorted alphabetically in PHP. Fine at school scale
     * (hundreds, not millions, of users).
     */
    public static function all(int $limit = 500, int $offset = 0): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users LIMIT :limit OFFSET :offset');
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $users = array_map([self::class, 'hydrate'], $stmt->fetchAll());
        usort($users, static fn($a, $b) => strcasecmp($a['display_name'], $b['display_name']));
        return $users;
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
        $email = self::normalizeEmail($email);
        $pdo = Database::connection();

        $existing = self::findBySiteUserId($siteUserId) ?? self::findByEmail($email);

        if ($existing) {
            $stmt = $pdo->prepare(
                'UPDATE users SET site_user_id = :site_user_id, email_cipher = :email_cipher, email_hash = :email_hash, display_name_cipher = :display_name_cipher WHERE id = :id'
            );
            $stmt->execute([
                'site_user_id' => $siteUserId,
                'email_cipher' => Crypto::encrypt($email),
                'email_hash' => Crypto::searchHash($email),
                'display_name_cipher' => Crypto::encrypt($displayName),
                'id' => $existing['id'],
            ]);
            $existing['site_user_id'] = $siteUserId;
            $existing['email'] = $email;
            $existing['display_name'] = $displayName;
            return $existing;
        }

        $insert = $pdo->prepare(
            "INSERT INTO users (site_user_id, email_cipher, email_hash, display_name_cipher, role) VALUES (:site_user_id, :email_cipher, :email_hash, :display_name_cipher, 'student')"
        );
        $insert->execute([
            'site_user_id' => $siteUserId,
            'email_cipher' => Crypto::encrypt($email),
            'email_hash' => Crypto::searchHash($email),
            'display_name_cipher' => Crypto::encrypt($displayName),
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
        $email = self::normalizeEmail($email);

        $existing = self::findByEmail($email);
        if ($existing) {
            return $existing;
        }

        $displayName = $displayName !== '' ? $displayName : $email;
        $pdo = Database::connection();
        $insert = $pdo->prepare('INSERT INTO users (email_cipher, email_hash, display_name_cipher, role) VALUES (:email_cipher, :email_hash, :display_name_cipher, :role)');
        $insert->execute([
            'email_cipher' => Crypto::encrypt($email),
            'email_hash' => Crypto::searchHash($email),
            'display_name_cipher' => Crypto::encrypt($displayName),
            'role' => $role,
        ]);
        $user = self::find((int) $pdo->lastInsertId());

        require_once __DIR__ . '/AuditLog.php';
        // entity_id already identifies which user this is - no need to duplicate their (identifiable) email into the audit payload too.
        AuditLog::record('user', (int) $user['id'], $actingAdminId, 'created_by_admin', null, ['role' => $role]);

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
        $email = self::normalizeEmail($email);
        $existing = self::findByEmail($email);
        if ($existing) {
            return $existing;
        }

        $pdo = Database::connection();
        $insert = $pdo->prepare(
            "INSERT INTO users (email_cipher, email_hash, display_name_cipher, role) VALUES (:email_cipher, :email_hash, :display_name_cipher, 'student')"
        );
        $insert->execute([
            'email_cipher' => Crypto::encrypt($email),
            'email_hash' => Crypto::searchHash($email),
            'display_name_cipher' => Crypto::encrypt($displayName),
        ]);
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

    /**
     * Sets which subject (matched against papers.subject) a Subject Leader
     * has department-wide authority over - e.g. deleting/managing any
     * paper in that subject, not just their own. Meaningless for other
     * roles, but not restricted to subject_leader here since an Admin may
     * set it ahead of a role change.
     */
    public static function setManagedSubject(int $userId, ?string $subject, int $actingAdminId): void
    {
        $subject = $subject !== null ? trim($subject) : null;
        $pdo = Database::connection();
        $before = self::find($userId);
        $stmt = $pdo->prepare('UPDATE users SET managed_subject = :subject WHERE id = :id');
        $stmt->execute(['subject' => $subject !== '' ? $subject : null, 'id' => $userId]);

        require_once __DIR__ . '/AuditLog.php';
        AuditLog::record('user', $userId, $actingAdminId, 'managed_subject_change',
            ['managed_subject' => $before['managed_subject'] ?? null],
            ['managed_subject' => $subject]
        );
    }

    public static function roleName(string $role): string
    {
        return in_array($role, self::ROLES, true) ? $role : self::ROLE_STUDENT;
    }
}
