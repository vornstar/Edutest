<?php
/** @var array $items */
$__title = 'Moderation queue';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>My moderation queue</h1>
    <table class="data-table">
        <thead><tr><th>Student</th><th>Mode</th><th>Tolerance</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $m): ?>
            <tr>
                <td><?= htmlspecialchars($m['student_name']) ?></td>
                <td><?= htmlspecialchars($m['mode']) ?></td>
                <td><?= htmlspecialchars((string) $m['tolerance']) ?></td>
                <td><?= htmlspecialchars($m['status']) ?></td>
                <td><a class="btn" href="/assessment/teacher/moderation/review/<?= (int) $m['id'] ?>">Review</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?>
            <tr><td colspan="5">Nothing allocated to you.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
