<?php
/** @var array $byStatus */
/** @var array $byPaper */
/** @var array $moderationVariance */
$__title = 'Reporting';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>Institution reporting</h1>

    <h2>Submissions by status</h2>
    <table class="data-table">
        <thead><tr><th>Status</th><th>Total</th></tr></thead>
        <tbody>
        <?php foreach ($byStatus as $row): ?>
            <tr><td><?= htmlspecialchars($row['status']) ?></td><td><?= (int) $row['total'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Performance by paper</h2>
    <table class="data-table">
        <thead><tr><th>Paper</th><th>Subject</th><th>Submissions</th><th>Average score</th></tr></thead>
        <tbody>
        <?php foreach ($byPaper as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['title']) ?></td>
                <td><?= htmlspecialchars($row['subject'] ?? '—') ?></td>
                <td><?= (int) $row['submissions'] ?></td>
                <td><?= $row['avg_score'] !== null ? htmlspecialchars(number_format((float) $row['avg_score'], 1)) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Moderation variance</h2>
    <table class="data-table">
        <thead><tr><th>Status</th><th>Count</th><th>Average variance</th></tr></thead>
        <tbody>
        <?php foreach ($moderationVariance as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['status']) ?></td>
                <td><?= (int) $row['total'] ?></td>
                <td><?= htmlspecialchars(number_format((float) $row['avg_variance'], 2)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
