<?php
$__title = 'New paper';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>New paper</h1>
    <form method="post" action="/assessment/teacher/papers" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">

        <label>Title <input type="text" name="title" required></label>
        <label>Subject <input type="text" name="subject"></label>
        <label>Duration (minutes) <input type="number" name="duration_minutes" min="1"></label>

        <fieldset>
            <legend>Delivery type</legend>
            <label><input type="radio" name="type" value="digital" checked onclick="document.getElementById('pdf-fields').hidden=true"> Digital questions</label>
            <label><input type="radio" name="type" value="pdf" onclick="document.getElementById('pdf-fields').hidden=false"> PDF exam paper</label>
        </fieldset>

        <div id="pdf-fields" hidden>
            <label>Exam paper PDF <input type="file" name="paper_pdf" accept="application/pdf"></label>
            <label>Mark scheme PDF <input type="file" name="mark_scheme_pdf" accept="application/pdf"></label>
        </div>

        <button type="submit" class="btn btn-primary">Create paper</button>
    </form>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
