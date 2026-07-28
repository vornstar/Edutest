<?php
/** @var array $files OneDrive Inbox files: id, name, size, lastModifiedDateTime */
/** @var array $pdfPapers manageable pdf-type papers, for the attach picker */
$__title = 'Bulk upload';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>Bulk upload</h1>
    <p class="autosave-status">
        Upload several exam paper and mark scheme PDFs at once into a shared OneDrive holding folder,
        then attach each one to the paper it belongs to whenever you're ready - nothing shows up on a
        paper until you attach it below, and nothing here is visible to students.
    </p>

    <?php if (isset($_GET['uploaded'])): ?>
        <p class="autosave-status">
            Uploaded <?= (int) $_GET['uploaded'] ?> file(s)<?= !empty($_GET['skipped']) ? ', skipped ' . (int) $_GET['skipped'] . ' non-PDF file(s)' : '' ?>.
        </p>
    <?php elseif (isset($_GET['attached'])): ?>
        <p class="autosave-status">Attached.</p>
    <?php endif; ?>

    <form method="post" action="/assessment/teacher/papers/inbox/upload" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
        <label>PDF files <input type="file" name="files[]" accept="application/pdf" multiple required></label>
        <button type="submit" class="btn btn-primary">Upload</button>
    </form>

    <h2>Unattached files</h2>
    <table class="data-table">
        <thead><tr><th>File</th><th>Uploaded</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($files as $f): ?>
            <tr>
                <td><?= htmlspecialchars($f['name']) ?></td>
                <td><?= htmlspecialchars($f['lastModifiedDateTime'] ?? '—') ?></td>
                <td>
                    <?php if ($pdfPapers): ?>
                        <form method="post" action="/assessment/teacher/papers/inbox/<?= htmlspecialchars($f['id']) ?>/attach" style="display:inline-flex;gap:0.5rem;align-items:center;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                            <select name="paper_id">
                                <?php foreach ($pdfPapers as $p): ?>
                                    <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="slot">
                                <option value="paper">Exam paper</option>
                                <option value="markscheme">Mark scheme</option>
                            </select>
                            <button type="submit" class="btn">Attach</button>
                        </form>
                    <?php else: ?>
                        <span class="autosave-status">No PDF-type papers to attach to yet - create one first.</span>
                    <?php endif; ?>
                    <form method="post" action="/assessment/teacher/papers/inbox/<?= htmlspecialchars($f['id']) ?>/delete" style="display:inline" onsubmit="return confirm('Delete this file from OneDrive? This cannot be undone.');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$files): ?>
            <tr><td colspan="3">No unattached files - upload some above.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
