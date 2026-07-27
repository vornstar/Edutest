<?php
/** @var array $paper */
/** @var array $questions */
/** @var bool $canDelete */
$__title = htmlspecialchars($paper['title']);
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <div class="panel-header">
        <h1><?= htmlspecialchars($paper['title']) ?></h1>
        <div>
            <a class="btn" href="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/preview" target="_blank">Preview as student</a>
            <a class="btn" href="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/assign">Assign to class</a>
            <?php if ($paper['status'] === 'draft'): ?>
                <form method="post" action="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/publish" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                    <button type="submit" class="btn btn-primary">Publish</button>
                </form>
            <?php endif; ?>
            <?php if ($canDelete): ?>
                <form method="post" action="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Delete this paper permanently, including all its questions? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                    <button type="submit" class="btn btn-danger">Delete paper</button>
                </form>
            <?php else: ?>
                <span class="autosave-status" title="Papers with student submissions can't be deleted.">Delete unavailable (has submissions)</span>
            <?php endif; ?>
        </div>
    </div>

    <p>Type: <?= htmlspecialchars($paper['type']) ?> &middot; Status: <?= htmlspecialchars($paper['status']) ?>
        &middot; Self-marking: <?= $paper['self_marking_enabled'] ? 'Enabled' : 'Disabled' ?></p>

    <?php if ($paper['type'] === 'pdf'): ?>
        <p>
            <?php if ($paper['pdf_drive_item_id']): ?><a href="/assessment/files/papers/<?= (int) $paper['id'] ?>/paper" target="_blank">View exam paper PDF</a><?php endif; ?>
            <?php if ($paper['mark_scheme_drive_item_id']): ?> &middot; <a href="/assessment/files/papers/<?= (int) $paper['id'] ?>/markscheme" target="_blank">View mark scheme PDF</a><?php endif; ?>
        </p>

        <h2>Replace PDF files</h2>
        <p>Upload a new file for either slot to replace what's currently stored - leave a slot empty to keep its existing file.</p>
        <form method="post" action="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/update-pdf" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
            <label>New exam paper PDF <input type="file" name="paper_pdf" accept="application/pdf"></label>
            <label>New mark scheme PDF <input type="file" name="mark_scheme_pdf" accept="application/pdf"></label>
            <button type="submit" class="btn">Replace file(s)</button>
        </form>
    <?php endif; ?>

    <h2>Questions (answer booklet structure)</h2>
    <table class="data-table">
        <thead><tr><th>#</th><th>Section</th><th>Type</th><th>Text</th><th>Max marks</th></tr></thead>
        <tbody>
        <?php foreach ($questions as $q): ?>
            <tr>
                <td><?= (int) $q['order_index'] ?></td>
                <td><?= htmlspecialchars($q['section'] ?? '') ?></td>
                <td><?= htmlspecialchars($q['type']) ?></td>
                <td><?= htmlspecialchars(mb_strimwidth($q['question_text'], 0, 80, '…')) ?></td>
                <td><?= htmlspecialchars((string) $q['max_marks']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$questions): ?>
            <tr><td colspan="5">No questions yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h2>Add a question</h2>
    <form method="post" action="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/questions">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
        <label>Section <input type="text" name="section"></label>
        <label>Order <input type="number" name="order_index" value="<?= count($questions) ?>"></label>
        <label>Type
            <select name="type">
                <option value="mcq">Multiple choice</option>
                <option value="short_answer">Short answer</option>
                <option value="extended_text">Extended text</option>
            </select>
        </label>
        <label>Question text <textarea name="question_text" rows="3" required></textarea></label>
        <label>Options (one per line, MCQ only) <textarea name="options" rows="3"></textarea></label>
        <label>Correct option letter (MCQ only, e.g. A) <input type="text" name="correct_option" maxlength="2"></label>
        <label>Max marks <input type="number" step="0.5" name="max_marks" value="1"></label>
        <label>Mark scheme (encrypted at rest) <textarea name="mark_scheme" rows="3"></textarea></label>
        <label>Model answer (encrypted at rest) <textarea name="model_answer" rows="3"></textarea></label>
        <button type="submit" class="btn btn-primary">Add question</button>
    </form>

    <h2>Bulk import questions (CSV)</h2>
    <p>Header: <code>section,type,question_text,options,correct_option,max_marks,mark_scheme,model_answer</code> &mdash; options is "|" separated for MCQ rows.</p>
    <form method="post" action="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/bulk-import" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
        <input type="file" name="csv_file" accept=".csv" required>
        <button type="submit" class="btn">Import CSV</button>
    </form>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
