<?php
/** @var array $assignment */
/** @var array $paper */
/** @var array $students */
$__title = 'Upload photographed scripts';
require __DIR__ . '/../partials/header.php';

$selectedStudentId = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;
$uploaded = isset($_GET['uploaded']);
$noPhotos = ($_GET['error'] ?? '') === 'no_photos';
?>
<div class="panel">
    <h1>Upload photographed scripts</h1>
    <p><?= htmlspecialchars($paper['title']) ?></p>

    <?php if ($uploaded): ?>
        <p class="autosave-status"><strong>Uploaded.</strong> Those photos were combined into a PDF and marked as that student's submission. Pick the next student below.</p>
    <?php endif; ?>
    <?php if ($noPhotos): ?>
        <p class="alert">Choose at least one photo before uploading.</p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" action="/assessment/teacher/assignments/<?= (int) $assignment['id'] ?>/upload-scan">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">

        <label>Student
            <select name="student_id" required>
                <option value="">Select a student&hellip;</option>
                <?php foreach ($students as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $selectedStudentId === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['display_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Photos of each page, in order
            <input type="file" name="photos[]" accept="image/*" multiple required>
        </label>
        <p class="autosave-status">
            Tap this to either take a photo with your camera or pick several already-taken photos from your
            gallery. Easiest on a phone: photograph every page of a student's script first with your normal
            camera app, then come back here and select all of them at once, in page order.
            Uploading again for the same student replaces their previous submission.
        </p>

        <button type="submit" class="btn btn-primary">Upload as this student's submission</button>
    </form>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
