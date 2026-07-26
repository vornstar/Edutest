<?php
/** @var array $classes */
$__title = 'My classes';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <div class="panel-header">
        <h1>My classes</h1>
        <a class="btn btn-primary" href="/assessment/teacher/classes/import">Import from Teams</a>
    </div>
    <table class="data-table">
        <thead><tr><th>Name</th><th>Subject</th><th>Teams-linked</th><th>Last synced</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($classes as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['name']) ?></td>
                <td><?= htmlspecialchars($c['subject'] ?? '—') ?></td>
                <td><?= $c['teams_class_id'] ? 'Yes' : 'No' ?></td>
                <td><?= htmlspecialchars($c['last_synced_at'] ?? 'never') ?></td>
                <td><a href="/assessment/teacher/classes/<?= (int) $c['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$classes): ?>
            <tr><td colspan="5">No classes yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
