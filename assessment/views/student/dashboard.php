<?php
/** @var array $assignments */
/** @var array $submissions */
$__title = 'My Tests';
require __DIR__ . '/../partials/header.php';

$submittedByAssignment = [];
foreach ($submissions as $s) {
    $submittedByAssignment[(int) $s['assignment_id']] = $s;
}
?>
<div class="panel">
    <h1>My tests</h1>
    <table class="data-table">
        <thead><tr><th>Paper</th><th>Type</th><th>Due</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($assignments as $a): $sub = $submittedByAssignment[(int) $a['id']] ?? null; ?>
            <tr>
                <td><?= htmlspecialchars($a['title']) ?></td>
                <td><?= htmlspecialchars($a['type']) ?></td>
                <td><?= htmlspecialchars($a['due_at'] ?? '—') ?></td>
                <td><?= htmlspecialchars($sub['status'] ?? 'not started') ?></td>
                <td>
                    <?php if (!$sub || $sub['status'] === 'in_progress'): ?>
                        <a class="btn" href="/assessment/student/assignments/<?= (int) $a['id'] ?>">Start / continue</a>
                    <?php else: ?>
                        <a class="btn" href="/assessment/student/submissions/<?= (int) $sub['id'] ?>">View</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$assignments): ?>
            <tr><td colspan="5">No tests assigned yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
