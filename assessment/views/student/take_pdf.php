<?php
/** @var array $paper */
/** @var array $submission */
/** @var array $questions */
/** @var array $answers */
$__title = htmlspecialchars($paper['title']);
require __DIR__ . '/../partials/header.php';
?>
<div class="panel test-panel pdf-mode" data-submission-id="<?= (int) $submission['id'] ?>" data-csrf="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
    <h1><?= htmlspecialchars($paper['title']) ?></h1>

    <div class="pdf-split">
        <div class="pdf-pane">
            <iframe title="Exam paper" src="/assessment/files/papers/<?= (int) $paper['id'] ?>/paper" class="pdf-frame"></iframe>
        </div>

        <div class="booklet-pane">
            <h2>Answer booklet</h2>
            <p class="autosave-status" id="autosave-status">Answers autosave as you type.</p>

            <form method="post" action="/assessment/student/submissions/<?= (int) $submission['id'] ?>/submit">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                <?php foreach ($questions as $q): $existing = $answers[(int) $q['id']]['answer_text'] ?? ''; ?>
                    <fieldset class="question-block" data-question-id="<?= (int) $q['id'] ?>">
                        <legend><?= htmlspecialchars($q['section'] ?? ('Q' . $q['id'])) ?> (<?= htmlspecialchars((string) $q['max_marks']) ?> marks)</legend>
                        <textarea class="answer-input" name="answer_<?= (int) $q['id'] ?>" rows="6"><?= htmlspecialchars($existing) ?></textarea>
                    </fieldset>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary">Submit test</button>
            </form>

            <hr>
            <h3>Or upload a scanned handwritten script</h3>
            <form method="post" enctype="multipart/form-data" action="/assessment/student/submissions/<?= (int) $submission['id'] ?>/scan">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                <input type="file" name="scan" accept="application/pdf,image/*" required>
                <button type="submit" class="btn">Upload scan</button>
            </form>
        </div>
    </div>
</div>
<script src="/assessment/assets/js/autosave.js"></script>
<?php require __DIR__ . '/../partials/footer.php'; ?>
