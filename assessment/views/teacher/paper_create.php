<?php
/** @var array $subjects */
$__title = 'New paper';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>New paper</h1>
    <form method="post" action="/assessment/teacher/papers" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">

        <label>Title <input type="text" name="title" required></label>
        <label>Subject
            <select name="subject">
                <option value="">&mdash; none &mdash;</option>
                <?php foreach ($subjects as $s): ?>
                    <option value="<?= htmlspecialchars($s['name']) ?>"><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if (!$subjects): ?>
            <p class="autosave-status">No subjects set up yet - an admin can add some in Admin &gt; Subjects.</p>
        <?php endif; ?>
        <label>Duration (minutes) <input type="number" name="duration_minutes" min="1"></label>

        <fieldset>
            <legend>Delivery type</legend>
            <label><input type="radio" name="type" value="digital" checked onclick="document.getElementById('pdf-fields').hidden=true"> Digital questions</label>
            <label><input type="radio" name="type" value="pdf" onclick="document.getElementById('pdf-fields').hidden=false"> PDF exam paper</label>
        </fieldset>

        <div id="pdf-fields" hidden>
            <label>Exam paper PDF <input type="file" name="paper_pdf" accept="application/pdf"></label>
            <label>Mark scheme PDF <input type="file" name="mark_scheme_pdf" accept="application/pdf"></label>
            <label>Max marks <input type="number" step="0.5" min="0" name="max_marks"></label>
            <p class="autosave-status">PDF papers don't need a question-by-question breakdown - students type/write directly on the PDF, and you enter one overall score out of this when marking.</p>
        </div>

        <button type="submit" class="btn btn-primary">Create paper</button>
    </form>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
