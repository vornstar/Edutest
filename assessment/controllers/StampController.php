<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/CustomStamp.php';

/** Per-marker custom quick-stamps for marking (SRS 7.1) - see CustomStamp model. */
final class StampController
{
    public static function add(): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();

        $label = trim((string) ($_POST['label'] ?? ''));
        if ($label !== '') {
            CustomStamp::create((int) $user['id'], mb_substr($label, 0, 20));
        }

        self::redirectBack();
    }

    public static function delete(int $stampId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();

        CustomStamp::delete($stampId, (int) $user['id']);

        self::redirectBack();
    }

    /** Sends the marker straight back to whichever marking/moderation page they added or removed a stamp from, rather than a generic landing page - only trusts a same-site referer to avoid an open redirect. */
    private static function redirectBack(): void
    {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $path = parse_url($referer, PHP_URL_PATH) ?: '';
        $target = str_starts_with($path, '/assessment/') ? $path : '/assessment/teacher/marking';

        header('Location: ' . $target);
        exit;
    }
}
