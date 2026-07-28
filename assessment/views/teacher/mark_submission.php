<?php
/** @var array $submission */
/** @var array $assignment */
/** @var array $paper */
/** @var array $questions */
/** @var array $answers */
/** @var array $selfMarks */
/** @var array $primaryMarks */
/** @var array $annotations */
/** @var array $teacherAnnotations keyed by page_number, this marker's own layer only */
/** @var array $studentAnnotations keyed by page_number, the student's layer at $selectedStudentVersion */
/** @var array $studentVersions each: ['version' => int, 'started_at' => string, 'last_saved_at' => string] */
/** @var int|null $latestStudentVersion */
/** @var int|null $selectedStudentVersion */
/** @var bool $viewingOldStudentVersion */
/** @var array $markSchemes keyed by question_id */
/** @var string|null $currentGrade live-preview grade from whatever's currently saved, or null if this paper has no grade boundaries (or no max marks set) */
/** @var int|null $nextUnmarkedId */
/** @var array $customStamps each: ['id' => int, 'label' => string] */
/** @var array $shortcuts stamp_label => shortcut key, this marker's own */
$__title = 'Marking';
require __DIR__ . '/../partials/header.php';

// A photographed physical script (see ScanUploadController) can be attached
// to ANY paper, not just pdf-type ones - so whether to show the PDF/scan
// viewer depends on whether a scan actually exists, not on the paper's
// nominal type. Typed answers only ever exist for a genuinely digital
// submission with no scan attached.
$hasScanOrPdf = $paper['type'] === 'pdf' || !empty($submission['scan_drive_item_id']);
$hasTypedAnswers = $paper['type'] === 'digital' && empty($submission['scan_drive_item_id']);

/** Renders one annotation-tool button, adding this marker's own keyboard shortcut (see StampShortcut) if they've set one. */
$__toolBtn = static function (string $tool, string $label, ?string $baseTitle = null) use ($shortcuts): string {
    $key = $shortcuts['tool'][$tool] ?? null;
    $title = $baseTitle ?? $label;
    if ($key) {
        $title .= ' (shortcut: ' . strtoupper($key) . ')';
    }
    return '<button type="button" data-tool="' . htmlspecialchars($tool) . '"'
        . ($key ? ' data-shortcut="' . htmlspecialchars($key) . '"' : '')
        . ' title="' . htmlspecialchars($title) . '">' . htmlspecialchars($label) . '</button>';
};
?>
<div class="panel marking-panel" data-submission-id="<?= (int) $submission['id'] ?>" data-csrf="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
    <h1><?= htmlspecialchars($paper['title']) ?></h1>

    <div class="mark-split">
        <div class="script-pane">
            <?php if ($hasScanOrPdf): ?>
                <?php if ($submission['scan_drive_item_id']): ?>
                    <p class="autosave-status">This is a photographed physical script.</p>
                <?php else: ?>
                    <p class="autosave-status">The student's own typing/writing on the PDF (if any) shows read-only in blue-ish tones on top - your marks go underneath, in whatever colour you pick below.</p>
                <?php endif; ?>

                <?php if (count($studentVersions) > 1): ?>
                    <form method="get" action="/assessment/teacher/marking/<?= (int) $submission['id'] ?>" style="max-width:20rem;">
                        <label>Student's attempt
                            <select name="student_version" onchange="this.form.submit()">
                                <?php foreach (array_reverse($studentVersions) as $v): ?>
                                    <option value="<?= (int) $v['version'] ?>" <?= $selectedStudentVersion === (int) $v['version'] ? 'selected' : '' ?>>
                                        Version <?= (int) $v['version'] ?><?= (int) $v['version'] === $latestStudentVersion ? ' (latest)' : '' ?> - started <?= htmlspecialchars($v['started_at']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <noscript><button type="submit" class="btn">Show</button></noscript>
                    </form>
                    <?php if ($viewingOldStudentVersion): ?>
                        <p class="autosave-status"><strong>Viewing an earlier attempt the student started over from</strong> - your own annotations still apply to their current (latest) attempt.</p>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="annotation-tools">
                    <?= $__toolBtn('pen', 'Pen') ?>
                    <?= $__toolBtn('highlighter', 'Highlighter') ?>
                    <?= $__toolBtn('text', 'Text') ?>
                    <?= $__toolBtn('circle', 'Circle', 'Drag to circle a mark - or just click for a default-sized circle') ?>
                    <?= $__toolBtn('delete', 'Delete selected') ?>
                    <input type="color" data-tool="color" value="<?= htmlspecialchars($__branding['teacher_marking_color'] ?? Branding::DEFAULT_TEACHER_MARKING_COLOR) ?>">
                    <?php require __DIR__ . '/../partials/stamp_toolbar.php'; ?>
                    <button type="button" id="save-annotation">Save annotations</button>
                    <span class="autosave-status" id="annotation-save-status">Also saves automatically when you change page.</span>
                    <button type="button" data-page-prev>&larr; Prev</button>
                    <span data-page-indicator>Page 1</span>
                    <button type="button" data-page-next>Next &rarr;</button>
                </div>
                <div class="annotation-stack">
                    <?php if ($submission['scan_drive_item_id']): ?>
                        <canvas id="annotation-canvas" class="annotation-canvas" data-pdf-src="/assessment/files/scans/<?= (int) $submission['id'] ?>"></canvas>
                    <?php else: ?>
                        <canvas id="annotation-canvas" class="annotation-canvas" data-pdf-src="/assessment/files/papers/<?= (int) $paper['id'] ?>/paper"></canvas>
                    <?php endif; ?>
                    <canvas id="annotation-student-layer" class="annotation-canvas annotation-student-layer"></canvas>
                </div>
            <?php else: ?>
                <p>Digital submission &mdash; see typed answers alongside each question below.</p>
            <?php endif; ?>
        </div>

        <div class="mark-pane">
            <?php if ($currentGrade !== null): ?>
                <p class="autosave-status"><strong>Current grade: <?= htmlspecialchars($currentGrade) ?></strong> (from whatever's saved so far - updates each time you save marks)</p>
            <?php endif; ?>
            <form method="post" action="/assessment/teacher/marking/<?= (int) $submission['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">

                <?php foreach ($questions as $q): $qid = (int) $q['id']; ?>
                    <fieldset class="question-block">
                        <legend><?= htmlspecialchars($q['section'] ?? '') ?> (max <?= htmlspecialchars((string) $q['max_marks']) ?>)</legend>

                        <?php if ($hasTypedAnswers): ?>
                            <p><strong>Answer:</strong> <?= nl2br(htmlspecialchars($answers[$qid]['answer_text'] ?? '')) ?></p>
                        <?php endif; ?>

                        <p class="mark-scheme"><strong>Mark scheme:</strong> <?= nl2br(htmlspecialchars($markSchemes[$qid] ?? 'Not provided')) ?></p>

                        <?php if (isset($selfMarks[$qid])): ?>
                            <p class="self-mark-note">Student self-mark: <?= htmlspecialchars((string) $selfMarks[$qid]['student_mark']) ?>
                                &mdash; <?= htmlspecialchars($selfMarks[$qid]['reflection_comment'] ?? '') ?></p>
                        <?php endif; ?>

                        <label>Score
                            <input type="number" step="0.5" min="0" max="<?= htmlspecialchars((string) $q['max_marks']) ?>"
                                   name="scores[<?= $qid ?>]"
                                   value="<?= htmlspecialchars((string) ($primaryMarks[$qid]['score'] ?? '')) ?>">
                        </label>
                        <label>Comment <textarea name="comments[<?= $qid ?>]" rows="2"><?= htmlspecialchars($primaryMarks[$qid]['comment'] ?? '') ?></textarea></label>
                    </fieldset>
                <?php endforeach; ?>

                <?php if (!$questions): ?>
                    <fieldset class="question-block">
                        <legend>Overall score<?= $paper['max_marks'] !== null ? ' (max ' . htmlspecialchars((string) $paper['max_marks']) . ')' : '' ?></legend>
                        <?php if ($paper['mark_scheme_drive_item_id']): ?>
                            <p><a class="btn" href="/assessment/files/papers/<?= (int) $paper['id'] ?>/markscheme" target="_blank">View mark scheme PDF</a></p>
                        <?php endif; ?>
                        <p class="autosave-status" id="page-marks-hint">Once the script has loaded, you can type a mark per page below instead - they'll add up into the total automatically. "Use tick count" fills a page's mark in from however many tick stamps are on it.</p>
                        <div id="page-marks-list" class="page-marks-list"></div>
                        <label>Total
                            <input type="number" step="0.5" min="0" <?= $paper['max_marks'] !== null ? 'max="' . htmlspecialchars((string) $paper['max_marks']) . '"' : '' ?>
                                   name="overall_score" id="overall-score-input"
                                   value="<?= htmlspecialchars((string) ($primaryMarks['overall']['score'] ?? '')) ?>">
                        </label>
                        <label>Comment <textarea name="overall_comment" rows="3"><?= htmlspecialchars($primaryMarks['overall']['comment'] ?? '') ?></textarea></label>
                    </fieldset>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary">Save marks</button>
            </form>

            <a class="btn" href="/assessment/teacher/moderation/<?= (int) $submission['id'] ?>/allocate">Send for moderation</a>
            <?php if ($nextUnmarkedId): ?>
                <a class="btn btn-primary" href="/assessment/teacher/marking/<?= (int) $nextUnmarkedId ?>">Next unmarked &rarr;</a>
            <?php else: ?>
                <span class="autosave-status">Nothing else awaiting marking for this paper.</span>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
window.__existingAnnotations = <?= json_encode($teacherAnnotations) ?>;
window.__studentAnnotations = <?= json_encode($studentAnnotations) ?>;
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js"></script>
<script src="<?= asset_url('/assets/js/pdf-annotate-core.js') ?>"></script>
<script src="<?= asset_url('/assets/js/canvas-annotate.js') ?>"></script>
<?php require __DIR__ . '/../partials/footer.php'; ?>
