<?php
/** @var array $flagged */
$__title = 'Flagged moderations';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>Flagged for department review</h1>
    <p>Moderation variances that exceeded their tolerance threshold.</p>
    <table class="data-table">
        <thead><tr><th>Submission</th><th>Mode</th><th>Tolerance</th><th>Variance</th><th>Completed</th></tr></thead>
        <tbody>
        <?php foreach ($flagged as $f): ?>
            <tr>
                <td>#<?= (int) $f['submission_id'] ?></td>
                <td><?= htmlspecialchars($f['mode']) ?></td>
                <td><?= htmlspecialchars((string) $f['tolerance']) ?></td>
                <td><?= htmlspecialchars((string) $f['variance']) ?></td>
                <td><?= htmlspecialchars($f['completed_at'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$flagged): ?>
            <tr><td colspan="5">No flagged moderations.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
