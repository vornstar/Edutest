<?php
/** @var array $moderation */
/** @var array $submission */
/** @var array $paper */
/** @var array $questions */
/** @var array $answers */
/** @var bool $showPrimary */
/** @var array $primaryMarks */
/** @var array $markSchemes */
/** @var array $annotations */
/** @var array $customStamps each: ['id' => int, 'label' => string] */
/** @var array $shortcuts stamp_label => shortcut key, this marker's own */
/** @var string|null $currentGrade live-preview grade from your own moderation marks saved so far, or null if this paper has no grade boundaries (or no max marks set) */
$__title = 'Moderation review';
require __DIR__ . '/../partials/header.php';

// See mark_submission.php - a photographed physical script can be attached
// to ANY paper, not just pdf-type ones, so the viewer/typed-answer display
// must key off whether a scan actually exists, not the paper's nominal type.
$hasScanOrPdf = $paper['type'] === 'pdf' || !empty($submission['scan_drive_item_id']);
$hasTypedAnswers = $paper['type'] === 'digital' && empty($submission['scan_drive_item_id']);

/** Renders one annotation-tool button, adding this marker's own keyboard shortcut (see StampShortcut) if they've set one. */
$__toolBtn = static function (string $tool, string $label, ?string $baseTitle = null) use ($shortcuts): string {
    $key = $shortcuts['tool'][$tool] ?? null;
    $title = $baseTitle ?? $label;
    if ($key) {
        $title .= ' (shortcut: ' . strtoupper($key) . ')';
    }
    return '<button type="button" data-tool="' . htmlspecialchars($tool) . '"'
        . ($key ? ' data-shortcut="' . htmlspecialchars($key) . '"' : '')
        . ' title="' . htmlspecialchars($title) . '">' . htmlspecialchars($label) . '</button>';
};
?>
<div class="panel marking-panel" data-submission-id="<?= (int) $submission['id'] ?>" data-csrf="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
    <h1><?= htmlspecialchars($paper['title']) ?> &mdash; <?= htmlspecialchars(ucfirst($moderation['mode'])) ?> moderation</h1>
    <?php if (!$showPrimary): ?>
        <p><em>Blind moderation: the primary marker's scores are hidden until you submit your own. The student's own script/answers are always visible.</em></p>
    <?php endif; ?>

    <div class="mark-split">
        <?php if ($hasScanOrPdf): ?>
        <div class="script-pane">
            <?php if ($submission['scan_drive_item_id']): ?>
                <p class="autosave-status">This is a photographed physical script.</p>
            <?php else: ?>
                <p class="autosave-status">The student's own typing/writing on the PDF (if any) shows read-only in blue-ish tones on top - your marks go underneath, in whatever colour you pick below.</p>
            <?php endif; ?>
            <div class="annotation-tools">
                <?= $__toolBtn('pen', 'Pen') ?>
                <?= $__toolBtn('highlighter', 'Highlighter') ?>
                <?= $__toolBtn('text', 'Text') ?>
                <?= $__toolBtn('circle', 'Circle', 'Drag to circle a mark - or just click for a default-sized circle') ?>
                <?= $__toolBtn('delete', 'Delete selected') ?>
                <input type="color" data-tool="color" value="<?= htmlspecialchars($__branding['teacher_moderation_color'] ?? Branding::DEFAULT_TEACHER_MODERATION_COLOR) ?>">
                <?php require __DIR__ . '/../partials/stamp_toolbar.php'; ?>
                <button type="button" id="save-annotation">Save annotations</button>
                <span class="autosave-status" id="annotation-save-status">Also saves automatically when you change page.</span>
                <button type="button" data-page-prev>&larr; Prev</button>
                <span data-page-indicator>Page 1</span>
                <button type="button" data-page-next>Next &rarr;</button>
            </div>
            <div class="annotation-stack">
                <?php if ($submission['scan_drive_item_id']): ?>
                    <canvas id="annotation-canvas" class="annotation-canvas" data-pdf-src="/assessment/files/scans/<?= (int) $submission['id'] ?>"></canvas>
                <?php else: ?>
                    <canvas id="annotation-canvas" class="annotation-canvas" data-pdf-src="/assessment/files/papers/<?= (int) $paper['id'] ?>/paper"></canvas>
                <?php endif; ?>
                <canvas id="annotation-student-layer" class="annotation-canvas annotation-student-layer"></canvas>
            </div>
        </div>
        <?php endif; ?>

        <div class="mark-pane">
            <?php if ($currentGrade !== null): ?>
                <p class="autosave-status"><strong>Current grade: <?= htmlspecialchars($currentGrade) ?></strong> (from your own moderation marks saved so far)</p>
            <?php endif; ?>
            <form method="post" action="/assessment/teacher/moderation/review/<?= (int) $moderation['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">

                <?php foreach ($questions as $q): $qid = (int) $q['id']; ?>
                    <fieldset class="question-block">
                        <legend><?= htmlspecialchars($q['section'] ?? '') ?> (max <?= htmlspecialchars((string) $q['max_marks']) ?>)</legend>
                        <?php if ($hasTypedAnswers): ?>
                            <p><strong>Answer:</strong> <?= nl2br(htmlspecialchars($answers[$qid]['answer_text'] ?? '')) ?></p>
                        <?php endif; ?>
                        <p class="mark-scheme"><strong>Mark scheme:</strong> <?= nl2br(htmlspecialchars($markSchemes[$qid] ?? 'Not provided')) ?></p>
                        <?php if ($showPrimary && isset($primaryMarks[$qid])): ?>
                            <p><strong>Primary marker score:</strong> <?= htmlspecialchars((string) $primaryMarks[$qid]['score']) ?> &mdash; <?= htmlspecialchars($primaryMarks[$qid]['comment'] ?? '') ?></p>
                        <?php endif; ?>
                        <label>Your score <input type="number" step="0.5" min="0" max="<?= htmlspecialchars((string) $q['max_marks']) ?>" name="scores[<?= $qid ?>]"></label>
                        <label>Comment <textarea name="comments[<?= $qid ?>]" rows="2"></textarea></label>
                    </fieldset>
                <?php endforeach; ?>

                <?php if (!$questions): ?>
                    <fieldset class="question-block">
                        <legend>Overall score<?= $paper['max_marks'] !== null ? ' (max ' . htmlspecialchars((string) $paper['max_marks']) . ')' : '' ?></legend>
                        <?php if ($paper['mark_scheme_drive_item_id']): ?>
                            <p><a class="btn" href="/assessment/files/papers/<?= (int) $paper['id'] ?>/markscheme" target="_blank">View mark scheme PDF</a></p>
                        <?php endif; ?>
                        <?php if ($showPrimary && isset($primaryMarks['overall'])): ?>
                            <p><strong>Primary marker score:</strong> <?= htmlspecialchars((string) $primaryMarks['overall']['score']) ?> &mdash; <?= htmlspecialchars($primaryMarks['overall']['comment'] ?? '') ?></p>
                        <?php endif; ?>
                        <p class="autosave-status" id="page-marks-hint">Once the script has loaded, you can type a mark per page below instead - they'll add up into the total automatically. "Use tick count" fills a page's mark in from however many tick stamps are on it.</p>
                        <div id="page-marks-list" class="page-marks-list"></div>
                        <label>Your total
                            <input type="number" step="0.5" min="0" <?= $paper['max_marks'] !== null ? 'max="' . htmlspecialchars((string) $paper['max_marks']) . '"' : '' ?> name="overall_score" id="overall-score-input">
                        </label>
                        <label>Comment <textarea name="overall_comment" rows="3"></textarea></label>
                    </fieldset>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary">Submit moderation</button>
            </form>
        </div>
    </div>
</div>
<?php if ($hasScanOrPdf): ?>
<script>
window.__existingAnnotations = <?php
    $byPage = [];
    foreach ($annotations as $a) {
        if ((int) $a['marker_id'] === (int) AuthController::currentUser()['id']) {
            $byPage[(int) $a['page_number']] = json_decode($a['data_json'], true);
        }
    }
    echo json_encode($byPage);
?>;
window.__studentAnnotations = <?php
    $studentByPage = [];
    foreach ($annotations as $a) {
        if ((int) $a['marker_id'] === (int) $submission['student_id']) {
            $studentByPage[(int) $a['page_number']] = json_decode($a['data_json'], true);
        }
    }
    echo json_encode($studentByPage);
?>;
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js"></script>
<script src="<?= asset_url('/assets/js/pdf-annotate-core.js') ?>"></script>
<script src="<?= asset_url('/assets/js/canvas-annotate.js') ?>"></script>
<?php endif; ?>
<?php require __DIR__ . '/../partials/footer.php'; ?>
