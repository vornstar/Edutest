<?php
/** @var array $submission */
/** @var array $paper */
/** @var array $questions */
/** @var array $answers */
/** @var array $selfMarks */
$__title = 'Self-marking';
require __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../../models/Question.php';
?>
<div class="panel">
    <h1>Self-marking</h1>
    <p>Compare your answers against the official mark scheme, then enter the mark you believe you earned and a short reflection for each question. Your teacher will review and moderate these marks.</p>

    <form method="post" action="/assessment/student/submissions/<?= (int) $submission['id'] ?>/self-mark">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">

        <?php foreach ($questions as $q):
            $qid = (int) $q['id'];
            $existingMark = $selfMarks[$qid]['student_mark'] ?? '';
            $existingReflection = $selfMarks[$qid]['reflection_comment'] ?? '';
        ?>
            <fieldset class="question-block">
                <legend><?= htmlspecialchars($q['section'] ?? '') ?> (max <?= htmlspecialchars((string) $q['max_marks']) ?>)</legend>
                <p><strong>Question:</strong> <?= nl2br(htmlspecialchars($q['question_text'])) ?></p>
                <p><strong>Your answer:</strong> <?= nl2br(htmlspecialchars($answers[$qid]['answer_text'] ?? '')) ?></p>
                <p class="mark-scheme"><strong>Mark scheme:</strong> <?= nl2br(htmlspecialchars(Question::decryptedMarkScheme($q) ?? 'Not provided')) ?></p>

                <label>Self-assessed mark (out of <?= htmlspecialchars((string) $q['max_marks']) ?>)
                    <input type="number" step="0.5" min="0" max="<?= htmlspecialchars((string) $q['max_marks']) ?>" name="marks[<?= $qid ?>]" value="<?= htmlspecialchars((string) $existingMark) ?>" required>
                </label>
                <label>Reflection comment
                    <textarea name="reflections[<?= $qid ?>]" rows="2"><?= htmlspecialchars($existingReflection) ?></textarea>
                </label>
            </fieldset>
        <?php endforeach; ?>

        <?php if ($questions): ?>
            <button type="submit" class="btn btn-primary">Submit self-assessment</button>
        <?php endif; ?>
    </form>

    <?php if (!$questions): ?>
        <?php if ($paper['type'] === 'pdf' && !empty($paper['mark_scheme_drive_item_id'])): ?>
            <p><a class="btn" href="/assessment/student/submissions/<?= (int) $submission['id'] ?>/mark-scheme" target="_blank">View mark scheme</a></p>
        <?php endif; ?>
        <p>This paper doesn't have a question-by-question breakdown to self-mark against - your teacher will give you an overall mark and feedback instead.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
