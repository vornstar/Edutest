<?php
/** @var array $paper */
/** @var array $submission Ignored in preview mode - pass ['id' => 0] */
/** @var array $questions */
/** @var array $answers */
/** @var bool $previewMode Optional - true when a teacher/admin is previewing, not a real student attempt */
$previewMode = $previewMode ?? false;
$__title = htmlspecialchars($paper['title']);
require __DIR__ . '/../partials/header.php';

require_once __DIR__ . '/../../models/Annotation.php';
$existingStudentAnnotations = [];
if (!$previewMode) {
    foreach (Annotation::forSubmission((int) $submission['id']) as $a) {
        $existingStudentAnnotations[(int) $a['page_number']] = json_decode($a['data_json'], true);
    }
}
?>
<div class="panel test-panel pdf-mode" data-submission-id="<?= (int) $submission['id'] ?>" data-csrf="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
    <h1><?= htmlspecialchars($paper['title']) ?></h1>

    <div class="pdf-split">
        <div class="pdf-pane">
            <?php if ($previewMode): ?>
                <iframe title="Exam paper" src="/assessment/files/papers/<?= (int) $paper['id'] ?>/paper" class="pdf-frame"></iframe>
            <?php else: ?>
                <h2>Type directly on the exam paper</h2>
                <p class="autosave-status" id="pdf-answer-status">Autosaves as you type/draw.</p>
                <div class="pdf-answer-tools">
                    <button type="button" data-answer-tool="pen">Pen</button>
                    <button type="button" data-answer-tool="text">Add text</button>
                    <button type="button" data-answer-tool="delete">Delete selected</button>
                    <button type="button" data-page-prev>&larr; Prev</button>
                    <span data-page-indicator>Page 1</span>
                    <button type="button" data-page-next>Next &rarr;</button>
                </div>
                <canvas id="pdf-answer-canvas" class="annotation-canvas" data-pdf-src="/assessment/files/papers/<?= (int) $paper['id'] ?>/paper"></canvas>
            <?php endif; ?>
        </div>

        <div class="booklet-pane">
            <h2>Answer booklet</h2>
            <p class="autosave-status">Optional - type answers here too if you'd rather not write on the PDF itself.</p>
            <?php if ($previewMode): ?>
                <p class="autosave-status"><strong>Preview mode</strong> &mdash; this is exactly what a student sees. Nothing entered here is saved, and this isn't a real attempt.</p>
            <?php else: ?>
                <p class="autosave-status" id="autosave-status">Answers autosave as you type.</p>
            <?php endif; ?>

            <form method="post" action="<?= $previewMode ? '#' : '/assessment/student/submissions/' . (int) $submission['id'] . '/submit' ?>" <?= $previewMode ? 'onsubmit="return false;"' : '' ?>>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                <?php foreach ($questions as $q): $existing = $answers[(int) $q['id']]['answer_text'] ?? ''; ?>
                    <fieldset class="question-block" data-question-id="<?= (int) $q['id'] ?>">
                        <legend><?= htmlspecialchars($q['section'] ?? ('Q' . $q['id'])) ?> (<?= htmlspecialchars((string) $q['max_marks']) ?> marks)</legend>
                        <textarea class="answer-input" name="answer_<?= (int) $q['id'] ?>" rows="6"><?= htmlspecialchars($existing) ?></textarea>
                    </fieldset>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary" <?= $previewMode ? 'disabled title="Preview only - nothing to submit"' : '' ?>>Submit test</button>
            </form>

            <?php if (!$previewMode): ?>
                <hr>
                <h3>Or upload a scanned handwritten script</h3>
                <form method="post" enctype="multipart/form-data" action="/assessment/student/submissions/<?= (int) $submission['id'] ?>/scan">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                    <input type="file" name="scan" accept="application/pdf,image/*" required>
                    <button type="submit" class="btn">Upload scan</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php if (!$previewMode): ?>
<script>window.__existingStudentAnnotations = <?= json_encode($existingStudentAnnotations) ?>;</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js"></script>
<script src="<?= asset_url('/assets/js/pdf-annotate-core.js') ?>"></script>
<script src="<?= asset_url('/assets/js/student-pdf-annotate.js') ?>"></script>
<script src="<?= asset_url('/assets/js/autosave.js') ?>"></script>
<?php endif; ?>
<?php require __DIR__ . '/../partials/footer.php'; ?>
