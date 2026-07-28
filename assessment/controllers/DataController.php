<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../models/Database.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Paper.php';
require_once __DIR__ . '/../models/TestAssignment.php';
require_once __DIR__ . '/../models/Submission.php';
require_once __DIR__ . '/../models/Mark.php';
require_once __DIR__ . '/../models/Question.php';

/**
 * Read-only aggregated reporting for the "Data" role (SRS 3.2). Every query
 * here is a SELECT only - this controller must never write.
 */
final class DataController
{
    public static function dashboard(): void
    {
        AuthController::requireRole([User::ROLE_DATA, User::ROLE_ADMIN, User::ROLE_SUBJECT_LEADER]);
        $pdo = Database::connection();

        $byStatus = $pdo->query(
            'SELECT s.status, COUNT(*) AS total FROM submissions s
             INNER JOIN test_assignments a ON a.id = s.assignment_id
             WHERE a.cancelled_at IS NULL AND a.class_id IS NOT NULL GROUP BY s.status'
        )->fetchAll();

        $byPaper = $pdo->query(
            'SELECT p.title, p.subject, COUNT(s.id) AS submissions, AVG(scores.total) AS avg_score
             FROM papers p
             LEFT JOIN test_assignments a ON a.paper_id = p.id AND a.cancelled_at IS NULL AND a.class_id IS NOT NULL
             LEFT JOIN submissions s ON s.assignment_id = a.id
             LEFT JOIN (
                 SELECT submission_id, SUM(score) AS total FROM (
                     SELECT m1.submission_id, m1.question_id, m1.score
                     FROM marks m1
                     INNER JOIN (
                         SELECT submission_id, question_id, MAX(id) AS max_id FROM marks
                         WHERE mark_type = "primary" GROUP BY submission_id, question_id
                     ) latest ON latest.max_id = m1.id
                 ) per_question GROUP BY submission_id
             ) scores ON scores.submission_id = s.id
             GROUP BY p.id ORDER BY p.created_at DESC'
        )->fetchAll();

        $moderationVariance = $pdo->query(
            "SELECT ma.status, COUNT(*) AS total, AVG(ma.variance) AS avg_variance FROM moderation_assignments ma
             INNER JOIN submissions s ON s.id = ma.submission_id
             INNER JOIN test_assignments a ON a.id = s.assignment_id
             WHERE ma.variance IS NOT NULL AND a.cancelled_at IS NULL AND a.class_id IS NOT NULL GROUP BY ma.status"
        )->fetchAll();

        require __DIR__ . '/../views/data/dashboard.php';
    }

    /**
     * Every individual student result across every paper visible to this
     * user (see Paper::visibleTo()) - for a Teacher or Subject Leader,
     * that's their own subject area including papers created/assigned by
     * OTHER teachers (SRS 3.2 department oversight for the Subject Leader,
     * subject-scoped read visibility for every Teacher); for an Admin,
     * every paper on the platform. The institution-wide dashboard() above
     * only ever shows aggregates; this is the per-student breakdown "see
     * the other teachers' classes' scores" actually needs.
     */
    public static function departmentResults(): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $papers = Paper::visibleTo($user);

        $classes = [];
        $rows = [];
        foreach ($papers as $paper) {
            $questions = Question::forPaper((int) $paper['id']);
            $maxTotal = $questions ? array_sum(array_column($questions, 'max_marks')) : (float) ($paper['max_marks'] ?? 0);
            $owner = User::find((int) $paper['created_by']);

            foreach (TestAssignment::forPaper((int) $paper['id']) as $assignment) {
                $classes[(int) $assignment['class_id']] = $assignment['class_name'];
                foreach (Submission::forAssignment((int) $assignment['id']) as $submission) {
                    $isMarked = in_array($submission['status'], ['marked', 'moderated'], true);
                    $rows[] = [
                        'paper_id' => $paper['id'],
                        'paper_title' => $paper['title'],
                        'teacher_name' => $owner['display_name'] ?? '—',
                        'class_id' => (int) $assignment['class_id'],
                        'class_name' => $assignment['class_name'],
                        'student_name' => $submission['student_name'],
                        'status' => $submission['status'],
                        'score' => $isMarked ? Mark::totalScore((int) $submission['id'], 'primary') : null,
                        'max' => $maxTotal,
                    ];
                }
            }
        }
        ksort($classes);

        $classFilter = !empty($_GET['class_id']) ? (int) $_GET['class_id'] : null;
        if ($classFilter !== null) {
            $rows = array_values(array_filter($rows, static fn(array $r): bool => $r['class_id'] === $classFilter));
        }

        require __DIR__ . '/../views/data/department_results.php';
    }
}
