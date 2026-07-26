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
        $user = AuthController::requireRole([User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER]);
        $classes = ClassRoster::forTeacher((int) $user['id']);
        require __DIR__ . '/../views/teacher/classes_index.php';
    }

    public static function browseTeamsClasses(): void
    {
        $user = AuthController::requireRole([User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER]);
        $teams = new TeamsService((int) $user['id']);
        $teamsClasses = $teams->listMyClasses();
        require __DIR__ . '/../views/teacher/classes_import.php';
    }

    public static function syncClass(): void
    {
        $user = AuthController::requireRole([User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER]);
        AuthController::verifyCsrf();

        $teamsClassId = (string) ($_POST['teams_class_id'] ?? '');
        if ($teamsClassId === '') {
            http_response_code(422);
            exit;
        }

        $teams = new TeamsService((int) $user['id']);
        $classId = $teams->syncClassRoster($teamsClassId, (int) $user['id']);

        header('Location: /assessment/teacher/classes/' . $classId);
        exit;
    }

    public static function classShow(int $classId): void
    {
        $user = AuthController::requireRole([User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER]);
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
