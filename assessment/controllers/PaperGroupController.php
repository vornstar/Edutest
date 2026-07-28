<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/PaperGroup.php';

/**
 * A teacher's own way of bundling related papers together - see PaperGroup
 * model / schema.sql for how this differs from Subject. Any teacher-portal
 * user can manage the group list, not just admins.
 */
final class PaperGroupController
{
    public static function index(): void
    {
        AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $groups = PaperGroup::all();
        require __DIR__ . '/../views/teacher/groups_index.php';
    }

    public static function add(): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '') {
            try {
                PaperGroup::create($name, (int) $user['id']);
            } catch (PDOException $e) {
                // Duplicate name (uq_paper_group_name) - it already exists, nothing to do.
            }
        }

        header('Location: /assessment/teacher/groups');
        exit;
    }

    public static function rename(int $groupId): void
    {
        AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '') {
            try {
                PaperGroup::rename($groupId, $name);
            } catch (PDOException $e) {
                // Duplicate name (uq_paper_group_name) - leave the existing entry as it was.
            }
        }

        header('Location: /assessment/teacher/groups');
        exit;
    }

    public static function delete(int $groupId): void
    {
        AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();

        PaperGroup::delete($groupId);

        header('Location: /assessment/teacher/groups');
        exit;
    }
}
