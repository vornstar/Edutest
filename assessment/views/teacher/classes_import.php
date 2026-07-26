<?php
/** @var array $teamsClasses */
$__title = 'Import from Teams';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>Import a class from Microsoft Teams</h1>
    <p>Classes are read from <code>/education/me/classes</code> via Microsoft Graph. Importing syncs the roster and links future assignment pushes to Teams.</p>
    <table class="data-table">
        <thead><tr><th>Class</th><th>Description</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($teamsClasses as $tc): ?>
            <tr>
                <td><?= htmlspecialchars($tc['displayName'] ?? '') ?></td>
                <td><?= htmlspecialchars($tc['description'] ?? '') ?></td>
                <td>
                    <form method="post" action="/assessment/teacher/classes/sync">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                        <input type="hidden" name="teams_class_id" value="<?= htmlspecialchars($tc['id'] ?? '') ?>">
                        <button type="submit" class="btn">Sync roster</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$teamsClasses): ?>
            <tr><td colspan="3">No Teams classes found for this account.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
