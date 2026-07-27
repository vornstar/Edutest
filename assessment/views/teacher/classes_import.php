<?php
/** @var array $teamsClasses */
$__title = 'Import from Teams';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>Import classes from Microsoft Teams</h1>
    <p>Classes are read from <code>/education/me/classes</code> via Microsoft Graph. Tick as many as you like and import them together - each syncs its roster and links future assignment pushes to Teams.</p>

    <form method="post" action="/assessment/teacher/classes/sync">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
        <table class="data-table">
            <thead>
                <tr>
                    <th><input type="checkbox" id="select-all-classes" onchange="document.querySelectorAll('.class-check').forEach(cb => cb.checked = this.checked)"></th>
                    <th>Class</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($teamsClasses as $tc): ?>
                <tr>
                    <td><input type="checkbox" class="class-check" name="teams_class_id[]" value="<?= htmlspecialchars($tc['id'] ?? '') ?>"></td>
                    <td><?= htmlspecialchars($tc['displayName'] ?? '') ?></td>
                    <td><?= htmlspecialchars($tc['description'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$teamsClasses): ?>
                <tr><td colspan="3">No Teams classes found for this account.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php if ($teamsClasses): ?>
            <button type="submit" class="btn btn-primary">Import selected classes</button>
        <?php endif; ?>
    </form>

    <?php if (isset($_GET['synced'])): ?>
        <p class="autosave-status">
            Imported <?= (int) $_GET['synced'] ?> class(es)<?= !empty($_GET['failed']) ? '; ' . (int) $_GET['failed'] . ' failed - try those again' : '' ?>.
        </p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
