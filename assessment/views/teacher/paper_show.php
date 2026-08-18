<?php
/** @var array $paper */
/** @var array $questions */
/** @var bool $canDelete */
/** @var bool $canManage */
/** @var array|null $selfTest */
/** @var array|null $selfTestSubmission */
/** @var array $groups */
/** @var array|null $paperGroup */
/** @var array $ownBoundaries this paper's own grade boundary bands, highest first (ignores borrowing) */
/** @var array|null $boundarySourcePaper the paper this one currently borrows boundaries from, if any */
/** @var array $boundaryCandidates same-group papers with their own boundaries, offered for borrowing */
$__title = htmlspecialchars($paper['title']);
require __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../../models/GradeBoundary.php';

/** Trims a trailing ".00"/".50" etc. down to whichever is cleanest, e.g. 20.0 -> "20", 12.5 -> "12.5". */
$formatMarks = static function (float $v): string {
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
};

$resolvedBoundaries = GradeBoundary::resolveForPaper($paper);
?>
<div class="panel">
    <div class="panel-header">
        <h1><?= htmlspecialchars($paper['title']) ?></h1>
        <div>
            <a class="btn" href="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/preview" target="_blank">Preview as student</a>
            <a class="btn" href="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/results">Results</a>
            <?php if (!$canManage): ?>
                <span class="autosave-status" title="Created by a colleague in your subject - you can view it, but only they (or a Subject Leader) can edit or assign it.">View only</span>
            <?php endif; ?>
            <?php if ($canManage): ?>
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
            <?php endif; ?>
        </div>
    </div>

    <p>Type: <?= htmlspecialchars($paper['type']) ?> &middot; Status: <?= htmlspecialchars($paper['status']) ?></p>
    <p class="autosave-status">Self-marking is set per-assignment now, not per-paper - see the class page for each assignment once it's been assigned.</p>

    <?php if ($canManage): ?>
        <form method="post" action="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/group" style="display:flex;gap:0.5rem;align-items:flex-end;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
            <label>Group
                <select name="group_id">
                    <option value="">&mdash; none &mdash;</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= (int) $g['id'] ?>" <?= $paperGroup && (int) $paperGroup['id'] === (int) $g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Or a new group <input type="text" name="new_group_name" maxlength="128" placeholder="Leave blank to use the dropdown"></label>
            <button type="submit" class="btn">Save</button>
        </form>
    <?php elseif ($paperGroup): ?>
        <p>Group: <?= htmlspecialchars($paperGroup['name']) ?></p>
    <?php endif; ?>

    <?php if ($paper['type'] === 'pdf' && $canManage): ?>
        <form method="post" action="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/max-marks" style="display:flex;gap:0.5rem;align-items:flex-end;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
            <label>Max marks (one overall score when marking, out of this)
                <input type="number" step="0.5" min="0" name="max_marks" value="<?= htmlspecialchars((string) ($paper['max_marks'] ?? '')) ?>">
            </label>
            <button type="submit" class="btn">Save</button>
        </form>
    <?php elseif ($paper['type'] === 'pdf'): ?>
        <p>Max marks: <?= htmlspecialchars((string) ($paper['max_marks'] ?? '—')) ?></p>
    <?php else: ?>
        <p class="autosave-status">Marks: total of each question's max marks below - edit a question's marks there to change the total.</p>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <form method="post" action="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/duration" style="display:flex;gap:0.5rem;align-items:flex-end;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
            <label>Duration (minutes)
                <input type="number" min="1" name="duration_minutes" value="<?= htmlspecialchars((string) ($paper['duration_minutes'] ?? '')) ?>">
            </label>
            <button type="submit" class="btn">Save</button>
        </form>
    <?php elseif ($paper['duration_minutes']): ?>
        <p>Duration: <?= (int) $paper['duration_minutes'] ?> minutes</p>
    <?php endif; ?>

    <div class="question-block">
        <h2 style="margin-top:0">Grade boundaries</h2>
        <p>Optional. Once set, a resolved grade shows straight away on the marking and moderation screens, and can be released to students (per test window, from Classes &gt; Open test windows) alongside this table.</p>

        <?php if ($resolvedBoundaries): ?>
            <table class="data-table" style="max-width:20rem;">
                <thead><tr><th>Grade</th><th>Minimum %</th></tr></thead>
                <tbody>
                <?php foreach ($resolvedBoundaries as $band): ?>
                    <tr><td><?= htmlspecialchars($band['grade_label']) ?></td><td><?= htmlspecialchars((string) $band['min_percent']) ?>%</td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="autosave-status">No grade boundaries set for this paper.</p>
        <?php endif; ?>

        <?php if ($canManage): ?>
            <?php if ($boundarySourcePaper): ?>
                <p class="autosave-status">Currently borrowing the table above from <strong><?= htmlspecialchars($boundarySourcePaper['title']) ?></strong> - the boxes below edit this paper's <em>own</em> boundaries, which only take effect once you switch back to "Use this paper's own boundaries" below.</p>
            <?php endif; ?>

            <form method="post" action="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/grade-boundaries">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                <label>This paper's own boundaries <span class="autosave-status">(one per line: grade,minimum % - e.g. "9,90" - saving switches this paper to use these, even if it was borrowing another paper's)</span>
                    <textarea name="boundaries" rows="6"><?php foreach ($ownBoundaries as $band): ?><?= htmlspecialchars($band['grade_label']) ?>,<?= htmlspecialchars((string) $band['min_percent']) ?>
<?php endforeach; ?></textarea>
                </label>
                <button type="submit" class="btn">Save own boundaries</button>
            </form>

            <?php if ($boundaryCandidates): ?>
                <form method="post" action="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/grade-boundaries/source" style="display:flex;gap:0.5rem;align-items:flex-end;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                    <label>Or borrow boundaries from another paper in this group
                        <select name="source_paper_id">
                            <option value="">&mdash; use this paper's own boundaries &mdash;</option>
                            <?php foreach ($boundaryCandidates as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= $boundarySourcePaper && (int) $boundarySourcePaper['id'] === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="btn">Save</button>
                </form>
            <?php elseif (!$paper['group_id']): ?>
                <p class="autosave-status">Put this paper in a group (see above) to borrow grade boundaries from another paper in it.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="question-block">
        <h2 style="margin-top:0">Test this paper before assigning it</h2>
        <p>Take the paper yourself exactly as a student would - typing/writing included - then go mark or moderate your own attempt. Nothing here is visible to students or counted in any report; it's just for you.</p>
        <?php if (!$selfTestSubmission): ?>
            <form method="post" action="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/start-test" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                <button type="submit" class="btn btn-primary">Start test</button>
            </form>
        <?php elseif ($selfTestSubmission['status'] === 'in_progress'): ?>
            <a class="btn btn-primary" href="/assessment/teacher/self-test/<?= (int) $selfTest['id'] ?>">Continue test</a>
        <?php else: ?>
            <?php // Once submitted, the writable self-test page (Pen/Text/autosave) would just fail every save with a confusing error - the answers/annotations are read-only from here on, same as a real student reviewing their marked work. ?>
            <a class="btn btn-primary" href="/assessment/student/submissions/<?= (int) $selfTestSubmission['id'] ?>">View your test answers</a>
            <a class="btn" href="/assessment/teacher/marking/<?= (int) $selfTestSubmission['id'] ?>">Mark your test submission</a>
            <form method="post" action="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/delete-test" style="display:inline" onsubmit="return confirm('Delete your test submission so you can start over?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                <button type="submit" class="btn">Delete test &amp; start over</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($paper['type'] === 'pdf'): ?>
        <p>
            <?php if ($paper['pdf_drive_item_id']): ?><a href="/assessment/files/papers/<?= (int) $paper['id'] ?>/paper" target="_blank">View exam paper PDF</a><?php endif; ?>
            <?php if ($paper['mark_scheme_drive_item_id']): ?> &middot; <a href="/assessment/files/papers/<?= (int) $paper['id'] ?>/markscheme" target="_blank">View mark scheme PDF</a><?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if ($paper['type'] === 'pdf' && $canManage): ?>
        <h2>Replace PDF files</h2>
        <p>Upload a new file for either slot to replace what's currently stored - leave a slot empty to keep its existing file. A Word document (.doc/.docx) is automatically converted to PDF on upload. Uploading several papers/mark schemes at once? Use <a href="/assessment/teacher/papers/inbox">Bulk upload</a> instead and attach them here later.</p>
        <form method="post" action="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/update-pdf" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
            <label>New exam paper PDF or Word document <input type="file" name="paper_pdf" accept="application/pdf,.pdf,.doc,.docx"></label>
            <label>New mark scheme PDF or Word document <input type="file" name="mark_scheme_pdf" accept="application/pdf,.pdf,.doc,.docx"></label>
            <button type="submit" class="btn">Replace file(s)</button>
        </form>
    <?php endif; ?>

    <?php if ($paper['type'] === 'digital' && $canManage): ?>
    <h2>Questions (answer booklet structure)</h2>
    <p class="autosave-status">Total marks: <?= htmlspecialchars($formatMarks(array_sum(array_column($questions, 'max_marks')))) ?></p>
    <table class="data-table">
        <thead><tr><th>#</th><th>Section</th><th>Type</th><th>Text</th><th>Max marks</th></tr></thead>
        <tbody>
        <?php foreach ($questions as $q): ?>
            <tr>
                <td><?= (int) $q['order_index'] ?></td>
                <td><?= htmlspecialchars($q['section'] ?? '') ?></td>
                <td><?= htmlspecialchars($q['type']) ?></td>
                <td><?= htmlspecialchars(mb_strimwidth($q['question_text'], 0, 80, '…')) ?></td>
                <td>
                    <form method="post" action="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/questions/<?= (int) $q['id'] ?>/marks" style="display:flex;gap:0.25rem;align-items:center;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                        <input type="number" step="0.5" min="0" name="max_marks" value="<?= htmlspecialchars((string) $q['max_marks']) ?>" style="width:5em">
                        <button type="submit" class="btn">Save</button>
                    </form>
                </td>
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
    <?php elseif ($paper['type'] === 'digital'): ?>
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
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
