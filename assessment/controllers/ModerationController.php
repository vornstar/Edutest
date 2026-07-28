<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../models/Submission.php';
require_once __DIR__ . '/../models/Moderation.php';
require_once __DIR__ . '/../models/Mark.php';
require_once __DIR__ . '/../models/Question.php';
require_once __DIR__ . '/../models/TestAssignment.php';
require_once __DIR__ . '/../models/Paper.php';
require_once __DIR__ . '/../models/Annotation.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/CustomStamp.php';
require_once __DIR__ . '/../services/TeamsService.php';

/**
 * Cross-teacher moderation (SRS 7.3): assignment allocation, open vs blind
 * marking, variance calculation, and Subject Leader tolerance alerts.
 */
final class ModerationController
{
    public static function allocateForm(int $submissionId): void
    {
        AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $submission = Submission::find($submissionId);
        if (!$submission) {
            http_response_code(404);
            exit;
        }
        require __DIR__ . '/../views/teacher/moderation_allocate.php';
    }

    public static function allocate(int $submissionId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();

        $submission = Submission::find($submissionId);
        if (!$submission) {
            http_response_code(404);
            exit;
        }

        $primaryMarks = Mark::latestForSubmission($submissionId, 'primary');
        $primaryMarkerId = $primaryMarks ? (int) reset($primaryMarks)['marker_id'] : null;

        Moderation::assign(
            $submissionId,
            $primaryMarkerId,
            (int) $_POST['secondary_marker_id'],
            ($_POST['mode'] ?? 'open') === 'blind' ? 'blind' : 'open',
            (float) ($_POST['tolerance'] ?? 2.0),
            (int) $user['id']
        );

        header('Location: /assessment/teacher/moderation/queue');
        exit;
    }

    public static function myQueue(): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $items = Moderation::forSecondaryMarker((int) $user['id']);
        require __DIR__ . '/../views/teacher/moderation_queue.php';
    }

    public static function review(int $moderationId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $moderation = Moderation::find($moderationId);
        if (!$moderation || (int) $moderation['secondary_marker_id'] !== (int) $user['id']) {
            http_response_code(404);
            exit;
        }

        $submission = Submission::find((int) $moderation['submission_id']);
        $assignment = TestAssignment::find((int) $submission['assignment_id']);
        $paper = Paper::find((int) $assignment['paper_id']);
        $questions = Question::forPaper((int) $paper['id']);
        $answers = Submission::answers((int) $submission['id']);

        // Blind moderation hides the primary marker's scores/annotations until this review is complete -
        // but the student's own work (typed answers, in-PDF annotations) is always visible either way,
        // since blind moderation is about not seeing the PRIMARY MARKER's judgement early, not the student's own script.
        $showPrimary = $moderation['mode'] === 'open' || $moderation['status'] !== 'pending';
        $primaryMarks = $showPrimary ? Mark::latestForSubmission((int) $submission['id'], 'primary') : [];
        $annotations = Annotation::forSubmission((int) $submission['id']);

        $markSchemes = [];
        foreach ($questions as $q) {
            $markSchemes[(int) $q['id']] = Question::decryptedMarkScheme($q);
        }
        $customStamps = CustomStamp::forUser((int) $user['id']);

        require __DIR__ . '/../views/teacher/moderation_review.php';
    }

    public static function submitReview(int $moderationId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        AuthController::verifyCsrf();

        $moderation = Moderation::find($moderationId);
        if (!$moderation || (int) $moderation['secondary_marker_id'] !== (int) $user['id']) {
            http_response_code(404);
            exit;
        }

        foreach ((array) ($_POST['scores'] ?? []) as $questionId => $score) {
            if ($score === '') {
                continue;
            }
            $comment = $_POST['comments'][$questionId] ?? null;
            Mark::record((int) $moderation['submission_id'], (int) $questionId, (int) $user['id'], (float) $score, $comment !== null ? (string) $comment : null, 'moderation');
        }

        if (isset($_POST['overall_score']) && $_POST['overall_score'] !== '') {
            $comment = $_POST['overall_comment'] ?? null;
            Mark::record((int) $moderation['submission_id'], null, (int) $user['id'], (float) $_POST['overall_score'], $comment !== null ? (string) $comment : null, 'moderation');
        }

        $result = Moderation::complete($moderationId);
        TeamsService::pushGradeForSubmission((int) $moderation['submission_id'], (int) $user['id'], 'moderation');
        require __DIR__ . '/../views/teacher/moderation_result.php';
    }

    /** Subject Leader dashboard of moderation variances exceeding tolerance. */
    public static function flagged(): void
    {
        AuthController::requireRole([User::ROLE_SUBJECT_LEADER, User::ROLE_ADMIN]);
        $flagged = Moderation::flaggedForDepartment();
        require __DIR__ . '/../views/teacher/moderation_flagged.php';
    }
}
