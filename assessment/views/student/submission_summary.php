<?php
/** @var array $submission */
/** @var array $paper */
/** @var array $assignment */
/** @var array $questions */
/** @var array $answers */
/** @var array $selfMarks */
/** @var bool $showFinalMarks */
/** @var array $finalMarks */
/** @var array $annotations */
$__title = 'Submission summary';
require __DIR__ . '/../partials/header.php';

$hasScanOrPdf = $paper['type'] === 'pdf' || !empty($submission['scan_drive_item_id']);
?>
<div class="panel">
    <h1><?= htmlspecialchars($paper['title']) ?></h1>
    <p>Status: <strong><?= htmlspecialchars($submission['status']) ?></strong></p>

    <?php if ($submission['status'] === 'submitted' && !empty($assignment['self_marking_enabled'])): ?>
        <a class="btn btn-primary" href="/assessment/student/submissions/<?= (int) $submission['id'] ?>/self-mark">Start self-marking</a>
    <?php endif; ?>

    <?php if ($showFinalMarks && $questions): ?>
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
    <?php elseif ($showFinalMarks): ?>
        <p>Overall score: <strong><?= htmlspecialchars((string) ($finalMarks['overall']['score'] ?? '—')) ?> / <?= htmlspecialchars((string) ($paper['max_marks'] ?? '—')) ?></strong></p>
        <?php if (!empty($finalMarks['overall']['comment'])): ?>
            <p>Feedback: <?= nl2br(htmlspecialchars($finalMarks['overall']['comment'])) ?></p>
        <?php endif; ?>
    <?php else: ?>
        <p>Your work has been submitted and is awaiting marking.</p>
    <?php endif; ?>

    <?php if ($showFinalMarks && $hasScanOrPdf): ?>
        <h2>Your marked script</h2>
        <div class="pdf-answer-tools review-tools">
            <button type="button" data-page-prev>&larr; Prev</button>
            <span data-page-indicator>Page 1</span>
            <button type="button" data-page-next>Next &rarr;</button>
        </div>
        <div class="annotation-stack script-pane">
            <canvas id="review-canvas" class="annotation-canvas"
                    data-pdf-src="<?= $submission['scan_drive_item_id']
                        ? '/assessment/files/scans/' . (int) $submission['id']
                        : '/assessment/files/papers/' . (int) $paper['id'] . '/paper' ?>"></canvas>
            <canvas id="review-marker-layer" class="annotation-canvas annotation-student-layer"></canvas>
        </div>
    <?php endif; ?>
</div>
<?php if ($showFinalMarks && $hasScanOrPdf): ?>
<script>
window.__myAnnotations = <?php
    $byPage = [];
    foreach ($annotations as $a) {
        if ((int) $a['marker_id'] === (int) $submission['student_id']) {
            $byPage[(int) $a['page_number']] = json_decode($a['data_json'], true);
        }
    }
    echo json_encode($byPage);
?>;
window.__markerAnnotations = <?php
    // Last one wins per page if more than one marker (primary + moderation) annotated it.
    $markerByPage = [];
    foreach ($annotations as $a) {
        if ((int) $a['marker_id'] !== (int) $submission['student_id']) {
            $markerByPage[(int) $a['page_number']] = json_decode($a['data_json'], true);
        }
    }
    echo json_encode($markerByPage);
?>;
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js"></script>
<script src="<?= asset_url('/assets/js/pdf-annotate-core.js') ?>"></script>
<script src="<?= asset_url('/assets/js/review-annotate.js') ?>"></script>
<?php endif; ?>
<?php require __DIR__ . '/../partials/footer.php'; ?>
