<?php
/** @var array $paper */
/** @var array $classes */
$__title = 'Assign paper';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>Assign "<?= htmlspecialchars($paper['title']) ?>"</h1>
    <form method="post" action="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/assign">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
        <label>Class
            <select name="class_id" required>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?><?= $c['teams_class_id'] ? ' (Teams-linked)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Due date/time <input type="datetime-local" name="due_at"></label>
        <label><input type="checkbox" name="sync_to_teams" value="1"> Push as a Microsoft Teams Assignment</label>
        <label><input type="checkbox" name="self_marking_enabled" value="1"> Allow self-marking for this assignment</label>
        <p class="autosave-status">You can turn this on or off later from the class page too - e.g. leave it off while students are still sitting the test, then switch it on once everyone's finished.</p>
        <button type="submit" class="btn btn-primary">Assign</button>
    </form>
    <?php if (!$classes): ?>
        <p>You have no classes yet. <a href="/assessment/teacher/classes/import">Import a class from Teams</a>.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
