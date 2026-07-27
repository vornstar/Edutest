<?php
/** @var array $class */
/** @var array $students */
/** @var array $assignments */
$__title = htmlspecialchars($class['name']);
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1><?= htmlspecialchars($class['name']) ?></h1>
    <p><?= count($students) ?> student(s) <?= $class['teams_class_id'] ? '&middot; linked to Microsoft Teams' : '' ?></p>

    <h2>Roster</h2>
    <ul class="roster-list">
        <?php foreach ($students as $s): ?>
            <li><?= htmlspecialchars($s['display_name']) ?> &lt;<?= htmlspecialchars($s['email']) ?>&gt;</li>
        <?php endforeach; ?>
    </ul>

    <h2>Assigned tests</h2>
    <table class="data-table">
        <thead><tr><th>Paper</th><th>Type</th><th>Due</th><th>Teams-synced</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($assignments as $a): ?>
            <tr>
                <td><?= htmlspecialchars($a['title']) ?></td>
                <td><?= htmlspecialchars($a['type']) ?></td>
                <td><?= htmlspecialchars($a['due_at'] ?? '—') ?></td>
                <td><?= $a['teams_assignment_id'] ? 'Yes' : 'No' ?></td>
                <td>
                    <a href="/assessment/teacher/papers/<?= (int) $a['paper_id'] ?>/preview" target="_blank">Preview as student</a>
                    &middot;
                    <a href="/assessment/teacher/assignments/<?= (int) $a['id'] ?>/upload-scan">Upload photographed scripts</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$assignments): ?>
            <tr><td colspan="5">No tests assigned to this class yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
