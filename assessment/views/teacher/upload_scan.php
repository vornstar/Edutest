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

    <form method="post" enctype="multipart/form-data" action="/assessment/teacher/assignments/<?= (int) $assignment['id'] ?>/upload-scan" id="scan-upload-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">

        <label>Student
            <select name="student_id" required>
                <option value="">Select a student&hellip;</option>
                <?php foreach ($students as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $selectedStudentId === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['display_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <div class="capture-controls">
            <button type="button" id="take-photo-btn" class="btn btn-primary">Take a photo</button>
            <button type="button" id="add-from-gallery-btn" class="btn">Add from gallery instead</button>
        </div>
        <p class="autosave-status">
            Take a photo of each page in order - it's added to the list below the moment you take it, no need to
            save photos anywhere first. Take another for the next page, and so on. Uploading again for the same
            student replaces their previous submission.
        </p>

        <input type="file" id="camera-input" accept="image/*" capture="environment" class="visually-hidden">
        <input type="file" id="gallery-input" accept="image/*" multiple class="visually-hidden">
        <input type="file" name="photos[]" id="photos-hidden-input" multiple class="visually-hidden" required>

        <ul id="photo-queue" class="photo-queue"></ul>
        <p class="autosave-status" id="queue-status">No pages captured yet.</p>

        <button type="submit" class="btn btn-primary" id="submit-btn" disabled>Upload as this student's submission</button>
    </form>
</div>
<script src="/assessment/assets/js/scan-capture.js"></script>
<?php require __DIR__ . '/../partials/footer.php'; ?>
