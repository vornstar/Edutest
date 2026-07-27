<?php
/** @var array $submission */
/** @var array $assignment */
/** @var array $paper */
/** @var array $questions */
/** @var array $answers */
/** @var array $selfMarks */
/** @var array $primaryMarks */
/** @var array $annotations */
/** @var array $markSchemes keyed by question_id */
/** @var int|null $nextUnmarkedId */
$__title = 'Marking';
require __DIR__ . '/../partials/header.php';

// A photographed physical script (see ScanUploadController) can be attached
// to ANY paper, not just pdf-type ones - so whether to show the PDF/scan
// viewer depends on whether a scan actually exists, not on the paper's
// nominal type. Typed answers only ever exist for a genuinely digital
// submission with no scan attached.
$hasScanOrPdf = $paper['type'] === 'pdf' || !empty($submission['scan_drive_item_id']);
$hasTypedAnswers = $paper['type'] === 'digital' && empty($submission['scan_drive_item_id']);
?>
<div class="panel marking-panel" data-submission-id="<?= (int) $submission['id'] ?>" data-csrf="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
    <h1><?= htmlspecialchars($paper['title']) ?></h1>

    <div class="mark-split">
        <div class="script-pane">
            <?php if ($hasScanOrPdf): ?>
                <?php if ($submission['scan_drive_item_id']): ?>
                    <p class="autosave-status">This is a photographed physical script.</p>
                <?php else: ?>
                    <p class="autosave-status">The student's own typing/writing on the PDF (if any) shows read-only in blue-ish tones on top - your marks go underneath, in whatever colour you pick below.</p>
                <?php endif; ?>
                <div class="annotation-tools">
                    <button type="button" data-tool="pen">Pen</button>
                    <button type="button" data-tool="highlighter">Highlighter</button>
                    <button type="button" data-tool="text">Text</button>
                    <button type="button" data-tool="delete">Delete selected</button>
                    <input type="color" data-tool="color" value="#e11d48">
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
            <?php else: ?>
                <p>Digital submission &mdash; see typed answers alongside each question below.</p>
            <?php endif; ?>
        </div>

        <div class="mark-pane">
            <form method="post" action="/assessment/teacher/marking/<?= (int) $submission['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">

                <?php foreach ($questions as $q): $qid = (int) $q['id']; ?>
                    <fieldset class="question-block">
                        <legend><?= htmlspecialchars($q['section'] ?? '') ?> (max <?= htmlspecialchars((string) $q['max_marks']) ?>)</legend>

                        <?php if ($hasTypedAnswers): ?>
                            <p><strong>Answer:</strong> <?= nl2br(htmlspecialchars($answers[$qid]['answer_text'] ?? '')) ?></p>
                        <?php endif; ?>

                        <p class="mark-scheme"><strong>Mark scheme:</strong> <?= nl2br(htmlspecialchars($markSchemes[$qid] ?? 'Not provided')) ?></p>

                        <?php if (isset($selfMarks[$qid])): ?>
                            <p class="self-mark-note">Student self-mark: <?= htmlspecialchars((string) $selfMarks[$qid]['student_mark']) ?>
                                &mdash; <?= htmlspecialchars($selfMarks[$qid]['reflection_comment'] ?? '') ?></p>
                        <?php endif; ?>

                        <label>Score
                            <input type="number" step="0.5" min="0" max="<?= htmlspecialchars((string) $q['max_marks']) ?>"
                                   name="scores[<?= $qid ?>]"
                                   value="<?= htmlspecialchars((string) ($primaryMarks[$qid]['score'] ?? '')) ?>">
                        </label>
                        <label>Comment <textarea name="comments[<?= $qid ?>]" rows="2"><?= htmlspecialchars($primaryMarks[$qid]['comment'] ?? '') ?></textarea></label>
                    </fieldset>
                <?php endforeach; ?>

                <?php if (!$questions): ?>
                    <fieldset class="question-block">
                        <legend>Overall score<?= $paper['max_marks'] !== null ? ' (max ' . htmlspecialchars((string) $paper['max_marks']) . ')' : '' ?></legend>
                        <label>Score
                            <input type="number" step="0.5" min="0" <?= $paper['max_marks'] !== null ? 'max="' . htmlspecialchars((string) $paper['max_marks']) . '"' : '' ?>
                                   name="overall_score"
                                   value="<?= htmlspecialchars((string) ($primaryMarks['overall']['score'] ?? '')) ?>">
                        </label>
                        <label>Comment <textarea name="overall_comment" rows="3"><?= htmlspecialchars($primaryMarks['overall']['comment'] ?? '') ?></textarea></label>
                    </fieldset>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary">Save marks</button>
            </form>

            <a class="btn" href="/assessment/teacher/moderation/<?= (int) $submission['id'] ?>/allocate">Send for moderation</a>
            <?php if ($nextUnmarkedId): ?>
                <a class="btn btn-primary" href="/assessment/teacher/marking/<?= (int) $nextUnmarkedId ?>">Next unmarked &rarr;</a>
            <?php else: ?>
                <span class="autosave-status">Nothing else awaiting marking for this paper.</span>
            <?php endif; ?>
        </div>
    </div>
</div>
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
<?php require __DIR__ . '/../partials/footer.php'; ?>
