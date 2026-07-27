<?php
/** @var array $paper */
/** @var array $rows each: ['submission' => array, 'class_name' => string, 'score' => float|null] */
/** @var float $maxTotal */
$__title = 'Results: ' . $paper['title'];
require __DIR__ . '/../partials/header.php';

$statusLabels = [
    'in_progress' => 'Still working',
    'submitted' => 'Submitted, awaiting marking',
    'self_marked' => 'Self-marked, awaiting review',
    'pending_moderation' => 'Awaiting moderation',
    'marked' => 'Marked',
    'moderated' => 'Marked & moderated',
];
?>
<div class="panel">
    <h1>Results: <?= htmlspecialchars($paper['title']) ?></h1>
    <p>Out of <?= htmlspecialchars((string) $maxTotal) ?> marks.</p>

    <table class="data-table">
        <thead><tr><th>Class</th><th>Student</th><th>Status</th><th>Score</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): $s = $row['submission']; ?>
            <tr>
                <td><?= htmlspecialchars($row['class_name']) ?></td>
                <td><?= htmlspecialchars($s['student_name']) ?></td>
                <td><?= htmlspecialchars($statusLabels[$s['status']] ?? $s['status']) ?></td>
                <td><?= $row['score'] !== null ? htmlspecialchars((string) $row['score']) . ' / ' . htmlspecialchars((string) $maxTotal) : '—' ?></td>
                <td><a class="btn" href="/assessment/teacher/marking/<?= (int) $s['id'] ?>"><?= $row['score'] !== null ? 'View/edit marks' : 'Mark' ?></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="5">No submissions yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
