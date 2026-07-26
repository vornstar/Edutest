<?php
/** @var array $moderation */
/** @var array $submission */
/** @var array $paper */
/** @var array $questions */
/** @var array $answers */
/** @var bool $showPrimary */
/** @var array $primaryMarks */
/** @var array $markSchemes */
$__title = 'Moderation review';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1><?= htmlspecialchars($paper['title']) ?> &mdash; <?= htmlspecialchars(ucfirst($moderation['mode'])) ?> moderation</h1>
    <?php if (!$showPrimary): ?>
        <p><em>Blind moderation: the primary marker's scores are hidden until you submit your own.</em></p>
    <?php endif; ?>

    <form method="post" action="/assessment/teacher/moderation/review/<?= (int) $moderation['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">

        <?php foreach ($questions as $q): $qid = (int) $q['id']; ?>
            <fieldset class="question-block">
                <legend><?= htmlspecialchars($q['section'] ?? '') ?> (max <?= htmlspecialchars((string) $q['max_marks']) ?>)</legend>
                <?php if ($paper['type'] === 'digital'): ?>
                    <p><strong>Answer:</strong> <?= nl2br(htmlspecialchars($answers[$qid]['answer_text'] ?? '')) ?></p>
                <?php endif; ?>
                <p class="mark-scheme"><strong>Mark scheme:</strong> <?= nl2br(htmlspecialchars($markSchemes[$qid] ?? 'Not provided')) ?></p>
                <?php if ($showPrimary && isset($primaryMarks[$qid])): ?>
                    <p><strong>Primary marker score:</strong> <?= htmlspecialchars((string) $primaryMarks[$qid]['score']) ?> &mdash; <?= htmlspecialchars($primaryMarks[$qid]['comment'] ?? '') ?></p>
                <?php endif; ?>
                <label>Your score <input type="number" step="0.5" min="0" max="<?= htmlspecialchars((string) $q['max_marks']) ?>" name="scores[<?= $qid ?>]"></label>
                <label>Comment <textarea name="comments[<?= $qid ?>]" rows="2"></textarea></label>
            </fieldset>
        <?php endforeach; ?>

        <button type="submit" class="btn btn-primary">Submit moderation</button>
    </form>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
