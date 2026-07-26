<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../models/User.php';

/**
 * Session bootstrap + Role-Based Access Control helpers shared by every
 * controller. Session cookies are the same ones set by the parent
 * auth_handler.php, so a login there is honored here without re-auth.
 */
final class AuthController
{
    public static function bootSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(config('session.name'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $lifetime = config('session.lifetime_minutes') * 60;
        if (!empty($_SESSION['login_at']) && (time() - (int) $_SESSION['login_at']) > $lifetime) {
            $_SESSION = [];
            session_destroy();
        }
    }

    public static function currentUser(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function requireLogin(): array
    {
        $user = self::currentUser();
        if (!$user) {
            $returnTo = '/assessment' . ($_SERVER['REQUEST_URI'] ?? '/');
            header('Location: /auth_handler.php?action=login&return_to=' . urlencode($returnTo));
            exit;
        }
        return $user;
    }

    /** @param int[] $roleIds */
    public static function requireRole(array $roleIds): array
    {
        $user = self::requireLogin();
        if (!in_array((int) $user['role_id'], $roleIds, true)) {
            http_response_code(403);
            require __DIR__ . '/../views/partials/forbidden.php';
            exit;
        }
        return $user;
    }

    public static function isTeacherLike(array $user): bool
    {
        return in_array((int) $user['role_id'], [User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER], true);
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(): void
    {
        $token = $_POST['csrf_token'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(419);
            echo 'Invalid or expired form token.';
            exit;
        }
    }
}
