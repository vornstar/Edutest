<?php
/** @var array $groups */
$__title = 'Paper groups';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <div class="panel-header">
        <h1>Paper groups</h1>
        <a class="btn" href="/assessment/teacher/papers">Back to Papers</a>
    </div>
    <p class="autosave-status">
        Your own way of bundling related papers together - e.g. every test for one topic or course.
        Anyone in the teacher portal can create or edit this list. Renaming or removing a group here
        doesn't delete any paper - it just changes what shows up as ungrouped or under the new name.
        Set a paper's group from the paper page, or when creating a new one.
    </p>

    <table class="data-table">
        <thead><tr><th>Name</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($groups as $g): ?>
            <tr>
                <td>
                    <form method="post" action="/assessment/teacher/groups/<?= (int) $g['id'] ?>/rename" style="display:flex;gap:0.4rem;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                        <input type="text" name="name" value="<?= htmlspecialchars($g['name']) ?>" required>
                        <button type="submit" class="btn">Rename</button>
                    </form>
                </td>
                <td>
                    <form method="post" action="/assessment/teacher/groups/<?= (int) $g['id'] ?>/delete" onsubmit="return confirm('Remove this group? Its papers stay - they just become ungrouped.');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                        <button type="submit" class="btn btn-danger">Remove</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$groups): ?>
            <tr><td colspan="2">No groups yet - add one below.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h2>Add a group</h2>
    <form method="post" action="/assessment/teacher/groups/add">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
        <label>Name <input type="text" name="name" placeholder="e.g. Year 11 Mock Papers" required></label>
        <button type="submit" class="btn btn-primary">Add</button>
    </form>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
