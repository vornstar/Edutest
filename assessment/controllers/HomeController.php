<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../models/User.php';

/** Routes the signed-in user to the correct portal home based on their role. */
final class HomeController
{
    public static function index(): void
    {
        $user = AuthController::requireLogin();
        switch ($user['role']) {
            case User::ROLE_STUDENT:
                header('Location: /assessment/student');
                break;
            case User::ROLE_TEACHER:
            case User::ROLE_SUBJECT_LEADER:
                header('Location: /assessment/teacher');
                break;
            case User::ROLE_DATA:
                header('Location: /assessment/data');
                break;
            case User::ROLE_ADMIN:
                header('Location: /assessment/admin/users');
                break;
            default:
                header('Location: /assessment/student');
        }
        exit;
    }
}
