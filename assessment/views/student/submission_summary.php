<?php
/** @var array $submission */
/** @var array $paper */
/** @var array $assignment */
/** @var array $questions */
/** @var array $answers */
/** @var array $selfMarks */
/** @var bool $showFinalMarks */
/** @var array $finalMarks */
$__title = 'Submission summary';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1><?= htmlspecialchars($paper['title']) ?></h1>
    <p>Status: <strong><?= htmlspecialchars($submission['status']) ?></strong></p>

    <?php if ($submission['status'] === 'submitted' && !empty($assignment['self_marking_enabled'])): ?>
        <a class="btn btn-primary" href="/assessment/student/submissions/<?= (int) $submission['id'] ?>/self-mark">Start self-marking</a>
    <?php endif; ?>

    <?php if ($showFinalMarks): ?>
        <table class="data-table">
            <thead><tr><th>Question</th><th>Your self-mark</th><th>Teacher mark</th><th>Max</th></tr></thead>
            <tbody>
            <?php $total = 0; $max = 0; foreach ($questions as $q): $qid = (int) $q['id']; $total += (float) ($finalMarks[$qid]['score'] ?? 0); $max += (float) $q['max_marks']; ?>
                <tr>
                    <td><?= htmlspecialchars($q['section'] ?? ('Q' . $qid)) ?></td>
                    <td><?= htmlspecialchars((string) ($selfMarks[$qid]['student_mark'] ?? '—')) ?></td>
                    <td><?= htmlspecialchars((string) ($finalMarks[$qid]['score'] ?? '—')) ?></td>
                    <td><?= htmlspecialchars((string) $q['max_marks']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="2"></td><td><strong><?= $total ?></strong></td><td><strong><?= $max ?></strong></td></tr></tfoot>
        </table>
    <?php else: ?>
        <p>Your work has been submitted and is awaiting marking.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
