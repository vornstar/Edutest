<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../models/Database.php';
require_once __DIR__ . '/../models/User.php';

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
            'SELECT status, COUNT(*) AS total FROM submissions GROUP BY status'
        )->fetchAll();

        $byPaper = $pdo->query(
            'SELECT p.title, p.subject, COUNT(s.id) AS submissions, AVG(scores.total) AS avg_score
             FROM papers p
             LEFT JOIN test_assignments a ON a.paper_id = p.id
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
            "SELECT status, COUNT(*) AS total, AVG(variance) AS avg_variance FROM moderation_assignments
             WHERE variance IS NOT NULL GROUP BY status"
        )->fetchAll();

        require __DIR__ . '/../views/data/dashboard.php';
    }
}
