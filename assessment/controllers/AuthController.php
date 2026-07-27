<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../models/User.php';

/**
 * Session bootstrap + Role-Based Access Control helpers shared by every
 * controller.
 *
 * The assessment platform does NOT run its own login flow. Authentication
 * happens once, site-wide, via the root /auth_handler.php (Microsoft Entra
 * ID), which leaves $_SESSION['user_id'], ['user_email'], ['user_name'],
 * ['access_token'] etc. set. This file just resumes that same PHP session
 * and layers the platform's own role (student/teacher/subject_leader/
 * data/admin - see models/User.php) on top, since that role lives in the
 * assessment platform's own database and is independent of the site's
 * broader student/staff distinction.
 */
final class AuthController
{
    public static function bootSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Deliberately no custom session_name()/cookie params here: this
            // must resume the exact same session the root db.php/
            // auth_handler.php started, using PHP's default session
            // handling, or single sign-on breaks.
            session_start();
        }
    }

    /**
     * Resolves the assessment-local user (id, role, ...) for whoever the
     * site has authenticated, syncing/creating that local row as needed.
     * Returns null if nobody is signed in to the site at all.
     */
    public static function currentUser(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        return User::syncFromSession(
            (int) $_SESSION['user_id'],
            (string) ($_SESSION['user_email'] ?? ''),
            (string) ($_SESSION['user_name'] ?? ($_SESSION['user_email'] ?? 'Unknown'))
        );
    }

    public static function requireLogin(): array
    {
        $user = self::currentUser();
        if (!$user) {
            // Reuses the site's existing post-login redirect convention
            // (see handle_redirect() in the root auth_handler.php) so the
            // user lands back on the assessment page they asked for.
            $_SESSION['redirect_to'] = $_SERVER['REQUEST_URI'] ?? '/assessment/';
            header('Location: /index.php');
            exit;
        }
        return $user;
    }

    /** @param string[] $roles One or more of User::ROLE_* */
    public static function requireRole(array $roles): array
    {
        $user = self::requireLogin();
        if (!in_array($user['role'], $roles, true)) {
            http_response_code(403);
            require __DIR__ . '/../views/partials/forbidden.php';
            exit;
        }
        return $user;
    }

    public static function isTeacherLike(array $user): bool
    {
        return in_array($user['role'], [User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER], true);
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Signs out of the site entirely (this is the same session the rest of
     * the site uses, so there is no separate "assessment-only" session to
     * tear down). The root site doesn't currently expose its own logout
     * endpoint, so this one clears the shared session and sends the user
     * back to the login page.
     */
    public static function logout(): void
    {
        self::bootSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        header('Location: /index.php');
        exit;
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
