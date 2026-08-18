<?php
/** @var array $subjects */
/** @var array $groups */
/** @var array $inboxFiles files already sitting in the Bulk upload Inbox: id, name, size, lastModifiedDateTime */
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

        <label>Group <span class="autosave-status">(optional - your own way of bundling related papers, e.g. by topic or course)</span>
            <select name="group_id">
                <option value="">&mdash; none &mdash;</option>
                <?php foreach ($groups as $g): ?>
                    <option value="<?= (int) $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Or create a new group <input type="text" name="new_group_name" maxlength="128" placeholder="Leave blank to use the dropdown above"></label>

        <label>Duration (minutes) <input type="number" name="duration_minutes" min="1"></label>

        <fieldset>
            <legend>Delivery type</legend>
            <label><input type="radio" name="type" value="digital" checked onclick="document.getElementById('pdf-fields').hidden=true"> Digital questions</label>
            <label><input type="radio" name="type" value="pdf" onclick="document.getElementById('pdf-fields').hidden=false"> PDF exam paper</label>
        </fieldset>

        <div id="pdf-fields" hidden>
            <label>Exam paper PDF or Word document <input type="file" name="paper_pdf" accept="application/pdf,.pdf,.doc,.docx"></label>
            <p class="autosave-status">A Word document (.doc/.docx) is automatically converted to PDF on upload - check it over on the paper's page afterwards, since conversion occasionally shifts unusual formatting slightly.</p>
            <?php if ($inboxFiles): ?>
                <label>Or choose an already-uploaded file
                    <select name="paper_pdf_inbox_id">
                        <option value="">&mdash; use the file picker above &mdash;</option>
                        <?php foreach ($inboxFiles as $f): ?>
                            <option value="<?= htmlspecialchars($f['id']) ?>"><?= htmlspecialchars($f['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <label>Mark scheme PDF or Word document <input type="file" name="mark_scheme_pdf" accept="application/pdf,.pdf,.doc,.docx"></label>
            <?php if ($inboxFiles): ?>
                <label>Or choose an already-uploaded file
                    <select name="mark_scheme_pdf_inbox_id">
                        <option value="">&mdash; use the file picker above &mdash;</option>
                        <?php foreach ($inboxFiles as $f): ?>
                            <option value="<?= htmlspecialchars($f['id']) ?>"><?= htmlspecialchars($f['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <p class="autosave-status">Picking a file from the dropdown moves it out of <a href="/assessment/teacher/papers/inbox" target="_blank">Bulk upload</a> onto this paper - it wins over anything chosen in the file picker above for the same slot.</p>
            <?php endif; ?>
            <label>Max marks <input type="number" step="0.5" min="0" name="max_marks"></label>
            <p class="autosave-status">PDF papers don't need a question-by-question breakdown - students type/write directly on the PDF, and you enter one overall score out of this when marking.</p>
        </div>

        <button type="submit" class="btn btn-primary">Create paper</button>
    </form>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
