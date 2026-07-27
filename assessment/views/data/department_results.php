<?php
/** @var array $rows */
$__title = 'Subject results';
require __DIR__ . '/../partials/header.php';

$statusLabels = [
    'in_progress' => 'Still working',
    'submitted' => 'Awaiting marking',
    'self_marked' => 'Self-marked, awaiting review',
    'pending_moderation' => 'Awaiting moderation',
    'marked' => 'Marked',
    'moderated' => 'Marked & moderated',
];
?>
<div class="panel">
    <h1>Subject results</h1>
    <p class="autosave-status">Every student result across every paper you can see (your own, plus anyone else's in your subject) - see the Papers page if you're not sure which papers that includes.</p>

    <table class="data-table">
        <thead><tr><th>Paper</th><th>Assigned by</th><th>Class</th><th>Student</th><th>Status</th><th>Score</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><a href="/assessment/teacher/papers/<?= (int) $row['paper_id'] ?>/results"><?= htmlspecialchars($row['paper_title']) ?></a></td>
                <td><?= htmlspecialchars($row['teacher_name']) ?></td>
                <td><?= htmlspecialchars($row['class_name']) ?></td>
                <td><?= htmlspecialchars($row['student_name']) ?></td>
                <td><?= htmlspecialchars($statusLabels[$row['status']] ?? $row['status']) ?></td>
                <td><?= $row['score'] !== null ? htmlspecialchars((string) $row['score']) . ' / ' . htmlspecialchars((string) $row['max']) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="6">No submissions yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
