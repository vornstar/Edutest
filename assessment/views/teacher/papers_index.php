<?php
/** @var array $papers */
/** @var array $user */
$__title = 'Papers';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <div class="panel-header">
        <h1>Papers</h1>
        <a class="btn btn-primary" href="/assessment/teacher/papers/create">New paper</a>
    </div>
    <table class="data-table">
        <thead><tr><th>Title</th><th>Subject</th><th>Type</th><th>Status</th><th>Owner</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($papers as $p): ?>
            <?php $canManage = PaperController::canManagePaper($user, $p); ?>
            <tr>
                <td><?= htmlspecialchars($p['title']) ?></td>
                <td><?= htmlspecialchars($p['subject'] ?? '—') ?></td>
                <td><?= htmlspecialchars($p['type']) ?></td>
                <td><?= htmlspecialchars($p['status']) ?></td>
                <td><?= (int) $p['created_by'] === (int) $user['id'] ? 'You' : 'Colleague' ?></td>
                <td>
                    <a href="/assessment/teacher/papers/<?= (int) $p['id'] ?>"><?= $canManage ? 'Manage' : 'View' ?></a>
                    <?php if ($canManage): ?>
                        &middot;
                        <a href="/assessment/teacher/papers/<?= (int) $p['id'] ?>/assign">Assign</a>
                    <?php endif; ?>
                    &middot;
                    <a href="/assessment/teacher/papers/<?= (int) $p['id'] ?>/results">Results</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$papers): ?>
            <tr><td colspan="6">No papers yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
