<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * A paper's grade boundary bands (e.g. "9" >= 90%) - optional, and
 * expressed as a percentage of the paper's own max marks so a set of
 * boundaries stays meaningful when another paper borrows it (see
 * papers.grade_boundary_source_paper_id / resolveForPaper) despite having
 * a different total. See PaperController::updateGradeBoundaries/
 * updateGradeBoundarySource for how a teacher edits either.
 */
final class GradeBoundary
{
    /** This paper's OWN bands, highest grade first - ignores grade_boundary_source_paper_id; see resolveForPaper() for what callers actually want. */
    public static function forPaper(int $paperId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM grade_boundaries WHERE paper_id = :paper_id ORDER BY min_percent DESC'
        );
        $stmt->execute(['paper_id' => $paperId]);
        return $stmt->fetchAll();
    }

    /** The bands that actually apply to this paper: its own, or - if it borrows from another paper - that paper's own bands instead. Empty if neither has any (grade boundaries are entirely optional). */
    public static function resolveForPaper(array $paper): array
    {
        $sourcePaperId = $paper['grade_boundary_source_paper_id'] ?? null;
        return self::forPaper($sourcePaperId ? (int) $sourcePaperId : (int) $paper['id']);
    }

    /** Replaces a paper's own band set wholesale - simpler and safer than diffing individual rows for a form that just re-submits the whole table each time. */
    public static function replaceForPaper(int $paperId, array $bands): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM grade_boundaries WHERE paper_id = :paper_id')->execute(['paper_id' => $paperId]);

        $stmt = $pdo->prepare('INSERT INTO grade_boundaries (paper_id, grade_label, min_percent) VALUES (:paper_id, :label, :min_percent)');
        foreach ($bands as $band) {
            $stmt->execute([
                'paper_id' => $paperId,
                'label' => $band['grade_label'],
                'min_percent' => $band['min_percent'],
            ]);
        }
    }

    /** The highest grade whose min_percent this percentage meets or exceeds, or null if it's below every band (or there are no bands at all). $boundaries must already be ordered min_percent DESC (see forPaper/resolveForPaper). */
    public static function gradeForPercent(array $boundaries, float $percent): ?string
    {
        foreach ($boundaries as $band) {
            if ($percent >= (float) $band['min_percent']) {
                return (string) $band['grade_label'];
            }
        }
        return null;
    }
}
