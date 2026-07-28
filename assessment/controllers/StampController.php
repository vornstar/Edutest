<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/CustomStamp.php';
require_once __DIR__ . '/../models/StampShortcut.php';

/** Per-marker custom quick-stamps for marking (SRS 7.1) - see CustomStamp model. */
final class StampController
{
    /**
     * Saves every shortcut in the "Keyboard shortcuts" dropdown in one submit,
     * rather than a separate save per row - see partials/stamp_toolbar.php.
     * Expects $_POST['shortcuts'] = ['tool' => [toolName => key], 'stamp' =>
     * [builtInLabel => key], 'custom' => [customStampId => key]]. A blank key
     * clears that shortcut (StampShortcut::set() already treats '' as clear).
     * Custom stamps are keyed by id, not label - CustomStamp doesn't enforce
     * unique labels per user, so two custom stamps could otherwise collide
     * under the same array key.
     */
    public static function setShortcuts(): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();
        $userId = (int) $user['id'];

        $submitted = (array) ($_POST['shortcuts'] ?? []);

        foreach ((array) ($submitted[StampShortcut::TARGET_TOOL] ?? []) as $tool => $key) {
            StampShortcut::set($userId, StampShortcut::TARGET_TOOL, (string) $tool, trim((string) $key));
        }
        foreach ((array) ($submitted[StampShortcut::TARGET_STAMP] ?? []) as $label => $key) {
            StampShortcut::set($userId, StampShortcut::TARGET_STAMP, (string) $label, trim((string) $key));
        }

        $customLabelsById = [];
        foreach (CustomStamp::forUser($userId) as $s) {
            $customLabelsById[(int) $s['id']] = $s['label'];
        }
        foreach ((array) ($submitted['custom'] ?? []) as $stampId => $key) {
            $label = $customLabelsById[(int) $stampId] ?? null;
            if ($label !== null) {
                StampShortcut::set($userId, StampShortcut::TARGET_STAMP, $label, trim((string) $key));
            }
        }

        self::redirectBack();
    }

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
