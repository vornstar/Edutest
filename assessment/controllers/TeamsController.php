<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../models/ClassRoster.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../services/TeamsService.php';

/**
 * Class roster import/sync from Microsoft Teams / Education Graph API
 * (SRS 3.3) and manual Teams status sync triggers.
 */
final class TeamsController
{
    public static function classesIndex(): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $classes = ClassRoster::forTeacher((int) $user['id']);
        require __DIR__ . '/../views/teacher/classes_index.php';
    }

    public static function browseTeamsClasses(): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $teams = new TeamsService((int) $user['id']);
        $teamsClasses = $teams->listMyClasses();
        require __DIR__ . '/../views/teacher/classes_import.php';
    }

    /**
     * Imports/syncs one or more Teams classes in a single submit - the
     * import picker (classes_import.php) posts an array of checked
     * teams_class_id[] values, so a teacher can pull in their whole set of
     * classes at once instead of one at a time.
     */
    public static function syncClass(): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();

        $teamsClassIds = array_values(array_filter(array_map(
            static fn($id) => trim((string) $id),
            (array) ($_POST['teams_class_id'] ?? [])
        )));

        if (!$teamsClassIds) {
            http_response_code(422);
            echo 'Select at least one class to import.';
            exit;
        }

        $teams = new TeamsService((int) $user['id']);
        $lastClassId = null;
        $errors = [];
        foreach ($teamsClassIds as $teamsClassId) {
            try {
                $lastClassId = $teams->syncClassRoster($teamsClassId, (int) $user['id']);
            } catch (Throwable $e) {
                $errors[] = $teamsClassId . ': ' . $e->getMessage();
            }
        }

        $synced = count($teamsClassIds) - count($errors);
        $query = http_build_query(['synced' => $synced, 'failed' => count($errors)]);

        // A single class syncs straight to its own page, matching the
        // previous one-at-a-time behaviour; multiple go to the class list
        // so every imported roster is visible at once.
        if (count($teamsClassIds) === 1 && !$errors && $lastClassId) {
            header('Location: /assessment/teacher/classes/' . $lastClassId);
        } else {
            header('Location: /assessment/teacher/classes?' . $query);
        }
        exit;
    }

    public static function classShow(int $classId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $class = ClassRoster::find($classId);
        if (!$class || (int) $class['owner_teacher_id'] !== (int) $user['id']) {
            http_response_code(404);
            exit;
        }
        $students = ClassRoster::students($classId);
        require_once __DIR__ . '/../models/TestAssignment.php';
        $assignments = TestAssignment::forClass($classId);
        require __DIR__ . '/../views/teacher/class_show.php';
    }
}
