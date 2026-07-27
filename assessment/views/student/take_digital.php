<?php
/** @var array $paper */
/** @var array $questions */
/** @var array $submission Ignored in preview mode - pass ['id' => 0] */
/** @var array $answers keyed by question_id */
/** @var bool $previewMode Optional - true when a teacher/admin is previewing, not a real student attempt */
$previewMode = $previewMode ?? false;
$__title = htmlspecialchars($paper['title']);
require __DIR__ . '/../partials/header.php';
?>
<div class="panel test-panel" data-submission-id="<?= (int) $submission['id'] ?>" data-csrf="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
    <h1><?= htmlspecialchars($paper['title']) ?></h1>
    <?php if ($previewMode): ?>
        <p class="autosave-status"><strong>Preview mode</strong> &mdash; this is exactly what a student sees. Nothing entered here is saved, and this isn't a real attempt.</p>
    <?php else: ?>
        <p class="autosave-status" id="autosave-status">Answers autosave as you type.</p>
    <?php endif; ?>

    <form method="post" action="<?= $previewMode ? '#' : '/assessment/student/submissions/' . (int) $submission['id'] . '/submit' ?>" <?= $previewMode ? 'onsubmit="return false;"' : '' ?>>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">

        <?php foreach ($questions as $q): $existing = $answers[(int) $q['id']]['answer_text'] ?? ''; ?>
            <fieldset class="question-block" data-question-id="<?= (int) $q['id'] ?>">
                <legend><?= htmlspecialchars($q['section'] ?? '') ?> (<?= htmlspecialchars((string) $q['max_marks']) ?> marks)</legend>
                <p><?= nl2br(htmlspecialchars($q['question_text'])) ?></p>

                <?php if ($q['type'] === 'mcq'): $options = json_decode((string) $q['options_json'], true) ?: []; ?>
                    <?php foreach ($options as $i => $opt): $key = chr(65 + $i); ?>
                        <label class="option">
                            <input type="radio" class="answer-input" name="answer_<?= (int) $q['id'] ?>" value="<?= htmlspecialchars($key) ?>" <?= $existing === $key ? 'checked' : '' ?>>
                            <?= htmlspecialchars($key . '. ' . $opt) ?>
                        </label>
                    <?php endforeach; ?>
                <?php else: ?>
                    <textarea class="answer-input" name="answer_<?= (int) $q['id'] ?>" rows="<?= $q['type'] === 'extended_text' ? 8 : 3 ?>"><?= htmlspecialchars($existing) ?></textarea>
                <?php endif; ?>
            </fieldset>
        <?php endforeach; ?>

        <button type="submit" class="btn btn-primary" <?= $previewMode ? 'disabled title="Preview only - nothing to submit"' : '' ?>>Submit test</button>
    </form>
</div>
<?php if (!$previewMode): ?>
<script src="<?= asset_url('/assets/js/autosave.js') ?>"></script>
<?php endif; ?>
<?php require __DIR__ . '/../partials/footer.php'; ?>
