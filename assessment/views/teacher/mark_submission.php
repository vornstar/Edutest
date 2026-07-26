<?php
/** @var array $submission */
/** @var array $assignment */
/** @var array $paper */
/** @var array $questions */
/** @var array $answers */
/** @var array $selfMarks */
/** @var array $primaryMarks */
/** @var array $annotations */
/** @var array $markSchemes keyed by question_id */
$__title = 'Marking';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel marking-panel" data-submission-id="<?= (int) $submission['id'] ?>" data-csrf="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
    <h1><?= htmlspecialchars($paper['title']) ?></h1>

    <div class="mark-split">
        <div class="script-pane">
            <?php if ($paper['type'] === 'pdf'): ?>
                <?php if ($submission['scan_drive_item_id']): ?>
                    <canvas id="annotation-canvas" class="annotation-canvas" data-pdf-src="/assessment/files/scans/<?= (int) $submission['id'] ?>"></canvas>
                <?php else: ?>
                    <canvas id="annotation-canvas" class="annotation-canvas" data-pdf-src="/assessment/files/papers/<?= (int) $paper['id'] ?>/paper"></canvas>
                <?php endif; ?>
                <div class="annotation-tools">
                    <button type="button" data-tool="pen">Pen</button>
                    <button type="button" data-tool="highlighter">Highlighter</button>
                    <button type="button" data-tool="text">Text</button>
                    <input type="color" data-tool="color" value="#e11d48">
                    <button type="button" id="save-annotation">Save annotations</button>
                </div>
            <?php else: ?>
                <p>Digital submission &mdash; see typed answers alongside each question below.</p>
            <?php endif; ?>
        </div>

        <div class="mark-pane">
            <form method="post" action="/assessment/teacher/marking/<?= (int) $submission['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">

                <?php foreach ($questions as $q): $qid = (int) $q['id']; ?>
                    <fieldset class="question-block">
                        <legend><?= htmlspecialchars($q['section'] ?? '') ?> (max <?= htmlspecialchars((string) $q['max_marks']) ?>)</legend>

                        <?php if ($paper['type'] === 'digital'): ?>
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

                <button type="submit" class="btn btn-primary">Save marks</button>
            </form>

            <a class="btn" href="/assessment/teacher/moderation/<?= (int) $submission['id'] ?>/allocate">Send for moderation</a>
        </div>
    </div>
</div>
<script>
window.__existingAnnotations = <?php
    $byPage = [];
    foreach ($annotations as $a) {
        if ((int) $a['marker_id'] === (int) AuthController::currentUser()['id']) {
            $byPage[(int) $a['page_number']] = json_decode($a['data_json'], true);
        }
    }
    echo json_encode($byPage);
?>;
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js"></script>
<script src="/assessment/assets/js/canvas-annotate.js"></script>
<?php require __DIR__ . '/../partials/footer.php'; ?>
