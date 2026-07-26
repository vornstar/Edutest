<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/TestAssignment.php';
require_once __DIR__ . '/../models/Submission.php';
require_once __DIR__ . '/../models/Mark.php';
require_once __DIR__ . '/../models/Paper.php';
require_once __DIR__ . '/../models/Question.php';

final class StudentController
{
    public static function dashboard(): void
    {
        $user = AuthController::requireRole([User::ROLE_STUDENT]);
        $assignments = TestAssignment::forStudent((int) $user['id']);
        $submissions = Submission::forStudent((int) $user['id']);
        require __DIR__ . '/../views/student/dashboard.php';
    }

    public static function submissionSummary(int $submissionId): void
    {
        $user = AuthController::requireRole([User::ROLE_STUDENT]);
        $submission = Submission::find($submissionId);
        if (!$submission || (int) $submission['student_id'] !== (int) $user['id']) {
            http_response_code(404);
            exit;
        }

        $assignment = TestAssignment::find((int) $submission['assignment_id']);
        $paper = Paper::find((int) $assignment['paper_id']);
        $questions = Question::forPaper((int) $paper['id']);
        $answers = Submission::answers($submissionId);
        $selfMarks = Submission::selfMarks($submissionId);

        $showFinalMarks = in_array($submission['status'], ['marked', 'moderated'], true);
        $finalMarks = $showFinalMarks ? Mark::latestForSubmission($submissionId, 'primary') : [];

        require __DIR__ . '/../views/student/submission_summary.php';
    }
}
