<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/PaperController.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/TestAssignment.php';
require_once __DIR__ . '/../models/Submission.php';
require_once __DIR__ . '/../models/Mark.php';
require_once __DIR__ . '/../models/GradeBoundary.php';
require_once __DIR__ . '/../models/Paper.php';
require_once __DIR__ . '/../models/Question.php';
require_once __DIR__ . '/../models/Annotation.php';

final class StudentController
{
    public static function dashboard(): void
    {
        $user = AuthController::requireRole([User::ROLE_STUDENT]);
        $allAssignments = TestAssignment::forStudent((int) $user['id']);
        $assignments = array_values(array_filter($allAssignments, static fn(array $a): bool => ($a['mode'] ?? 'assigned') !== 'self_service'));
        $selfServiceAssignments = array_values(array_filter($allAssignments, static fn(array $a): bool => ($a['mode'] ?? 'assigned') === 'self_service'));
        $submissions = Submission::forStudent((int) $user['id']);
        require __DIR__ . '/../views/student/dashboard.php';
    }

    /**
     * Renders the exact same feedback a student sees - marks, comments, and
     * their annotated script - for three kinds of viewer: the real student
     * it belongs to; a self-testing teacher-portal user (startOrGet()
     * records them as the submission's own student_id, so the ownership
     * check below already covers this case with no special-casing); or a
     * teacher-portal user who can at least VIEW the underlying paper (see
     * PaperController::canViewPaper - same authority tier the Results page
     * itself already uses, so anyone who can see a student's score there
     * can also open "View as student" for it, nothing wider), previewing a
     * real student's feedback. Only the page's own content is identical to
     * what the student sees - the surrounding site header/nav still
     * reflects whoever is actually signed in, since this isn't real
     * account impersonation.
     */
    public static function submissionSummary(int $submissionId): void
    {
        $user = AuthController::requireLogin();
        $submission = Submission::find($submissionId);
        if (!$submission) {
            http_response_code(404);
            exit;
        }
        $isOwner = (int) $submission['student_id'] === (int) $user['id'];
        if (!$isOwner) {
            $assignment = TestAssignment::find((int) $submission['assignment_id']);
            $paper = $assignment ? Paper::find((int) $assignment['paper_id']) : null;
            if (!$paper || !PaperController::canViewPaper($user, $paper)) {
                http_response_code(404);
                exit;
            }
        }

        $assignment = TestAssignment::find((int) $submission['assignment_id']);
        $paper = Paper::find((int) $assignment['paper_id']);
        $questions = Question::forPaper((int) $paper['id']);
        $answers = Submission::answers($submissionId);
        $selfMarks = Submission::selfMarks($submissionId);

        // A self-service assignment (see TestController::releaseSelfService) has no
        // teacher marking step at all - its own terminal status is 'self_marked', and
        // "final" means the student's own self-mark, not a teacher's.
        $isSelfService = ($assignment['mode'] ?? 'assigned') === 'self_service';
        $showFinalMarks = $isSelfService
            ? $submission['status'] === 'self_marked'
            : in_array($submission['status'], ['marked', 'moderated'], true);
        $finalMarks = ($showFinalMarks && !$isSelfService) ? Mark::latestForSubmission($submissionId, 'primary') : [];
        $annotations = $showFinalMarks ? Annotation::forSubmission($submissionId) : [];

        // Only ever shown once the teacher has explicitly released grades for THIS
        // assignment (see TestController::releaseGrades) - never just because marking
        // is done, and never a raw fetch a student could reach some other way.
        $gradesReleased = $showFinalMarks && !empty($assignment['grade_released_at']);
        $releasedGrade = null;
        $releasedBoundaries = [];
        if ($gradesReleased) {
            $releasedBoundaries = GradeBoundary::resolveForPaper($paper);
            $maxMarksTotal = Paper::maxMarksFor($paper, $questions);
            if ($releasedBoundaries && $maxMarksTotal > 0) {
                $achievedScore = $isSelfService ? Submission::selfMarkTotal($submissionId) : Mark::totalScore($submissionId, 'primary');
                $releasedGrade = GradeBoundary::gradeForPercent($releasedBoundaries, $achievedScore / $maxMarksTotal * 100);
            }
        }

        require __DIR__ . '/../views/student/submission_summary.php';
    }
}
