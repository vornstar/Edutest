<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../models/ClassRoster.php';
require_once __DIR__ . '/../models/TestAssignment.php';
require_once __DIR__ . '/../models/Submission.php';
require_once __DIR__ . '/../models/Paper.php';
require_once __DIR__ . '/../models/Question.php';
require_once __DIR__ . '/../models/Mark.php';
require_once __DIR__ . '/../models/GradeBoundary.php';
require_once __DIR__ . '/../models/User.php';

/**
 * One class's whole record in a single grid: every student down the side,
 * every paper assigned to the class along the top, score/grade in each
 * cell. Reuses the exact same score/grade resolution as everywhere else
 * (Mark::totalScore for a normal assignment, Submission::selfMarkTotal
 * for a self-service one - see TestController::releaseSelfService) so a
 * cell here always agrees with what the Results page or a student's own
 * submission page shows.
 */
final class MarkbookController
{
    /**
     * Same authority tier as managing a paper (SRS 3.2 department
     * oversight): the class's own owner, an Admin, or a Subject Leader
     * whose managed subject matches the class's.
     */
    private static function canViewClass(array $user, array $class): bool
    {
        if ((int) $class['owner_teacher_id'] === (int) $user['id']) {
            return true;
        }
        if ($user['role'] === User::ROLE_ADMIN) {
            return true;
        }
        if ($user['role'] === User::ROLE_SUBJECT_LEADER && !empty($user['managed_subject'])) {
            return strcasecmp((string) $user['managed_subject'], (string) ($class['subject'] ?? '')) === 0;
        }
        return false;
    }

    private static function requireViewableClass(int $classId, array $user): array
    {
        $class = ClassRoster::find($classId);
        if (!$class || !self::canViewClass($user, $class)) {
            http_response_code(404);
            exit;
        }
        return $class;
    }

    /**
     * Builds the grid: [studentId][assignmentId] => ['status','score','max','grade'].
     * @return array{0:array,1:array} [$cells, $paperMeta] - $paperMeta keyed by assignment id: ['title','mode','max']
     */
    private static function buildGrid(int $classId): array
    {
        $assignments = TestAssignment::forClass($classId);

        $cells = [];
        $paperMeta = [];

        foreach ($assignments as $a) {
            $paper = Paper::find((int) $a['paper_id']);
            if (!$paper) {
                continue;
            }
            $questions = Question::forPaper((int) $paper['id']);
            $maxMarks = Paper::maxMarksFor($paper, $questions);
            $boundaries = GradeBoundary::resolveForPaper($paper);
            $isSelfService = ($a['mode'] ?? 'assigned') === 'self_service';

            $paperMeta[(int) $a['id']] = [
                'title' => $a['title'],
                'mode' => $a['mode'] ?? 'assigned',
                'max' => $maxMarks,
            ];

            foreach (Submission::forAssignment((int) $a['id']) as $sub) {
                $studentId = (int) $sub['student_id'];
                $submissionId = (int) $sub['id'];

                if ($isSelfService) {
                    $isFinal = $sub['status'] === 'self_marked';
                    $score = $isFinal ? Submission::selfMarkTotal($submissionId) : null;
                } else {
                    $isFinal = in_array($sub['status'], ['marked', 'moderated'], true);
                    $score = $isFinal ? Mark::totalScore($submissionId, 'primary') : null;
                }

                $grade = ($score !== null && $boundaries && $maxMarks > 0)
                    ? GradeBoundary::gradeForPercent($boundaries, $score / $maxMarks * 100)
                    : null;

                $cells[$studentId][(int) $a['id']] = [
                    'status' => $sub['status'],
                    'score' => $score,
                    'grade' => $grade,
                ];
            }
        }

        return [$cells, $paperMeta];
    }

    public static function show(int $classId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $class = self::requireViewableClass($classId, $user);

        $students = ClassRoster::students($classId);
        [$cells, $paperMeta] = self::buildGrid($classId);

        require __DIR__ . '/../views/teacher/markbook.php';
    }

    public static function exportCsv(int $classId): void
    {
        $user = AuthController::requireRole(User::TEACHER_PORTAL_ROLES);
        $class = self::requireViewableClass($classId, $user);

        $students = ClassRoster::students($classId);
        [$cells, $paperMeta] = self::buildGrid($classId);

        $filename = 'markbook-' . preg_replace('/[^A-Za-z0-9]+/', '-', $class['name']) . '-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        $header = ['Student', 'Email'];
        foreach ($paperMeta as $meta) {
            $header[] = $meta['title'] . ($meta['mode'] === 'self_service' ? ' (self-service)' : '');
        }
        fputcsv($out, $header);

        foreach ($students as $s) {
            $row = [$s['display_name'], $s['email']];
            foreach (array_keys($paperMeta) as $assignmentId) {
                $cell = $cells[(int) $s['id']][$assignmentId] ?? null;
                if (!$cell || $cell['score'] === null) {
                    $row[] = '';
                } else {
                    $row[] = $cell['score'] . ($cell['grade'] !== null ? ' (' . $cell['grade'] . ')' : '');
                }
            }
            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }
}
