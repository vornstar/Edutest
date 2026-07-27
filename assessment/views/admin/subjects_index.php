<?php
/** @var array $subjects */
$__title = 'Subjects';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>Subjects</h1>
    <p class="autosave-status">
        The list every subject dropdown offers - assigning a teacher's subject in Admin &gt; Users, and
        setting a paper's subject when creating one. Renaming or removing an entry here doesn't change
        any teacher or paper already assigned to it (they're matched by name, not linked) - it only
        changes what's offered going forward.
    </p>

    <table class="data-table">
        <thead><tr><th>Name</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($subjects as $s): ?>
            <tr>
                <td>
                    <form method="post" action="/assessment/admin/subjects/<?= (int) $s['id'] ?>/rename" style="display:flex;gap:0.4rem;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                        <input type="text" name="name" value="<?= htmlspecialchars($s['name']) ?>" required>
                        <button type="submit" class="btn">Rename</button>
                    </form>
                </td>
                <td>
                    <form method="post" action="/assessment/admin/subjects/<?= (int) $s['id'] ?>/delete" onsubmit="return confirm('Remove this subject from the list? Existing teachers/papers keep their current value.');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                        <button type="submit" class="btn btn-danger">Remove</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$subjects): ?>
            <tr><td colspan="2">No subjects yet - add one below.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h2>Add a subject</h2>
    <form method="post" action="/assessment/admin/subjects/add">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
        <label>Name <input type="text" name="name" placeholder="e.g. Biology" required></label>
        <button type="submit" class="btn btn-primary">Add</button>
    </form>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
