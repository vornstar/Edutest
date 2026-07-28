<?php
/** @var array $rows */
/** @var array $classes [class_id => class_name] every class among the visible papers */
/** @var int|null $classFilter */
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

    <?php if (count($classes) > 1): ?>
        <form method="get" action="/assessment/data/department-results" style="max-width:16rem;">
            <label>Class
                <select name="class_id" onchange="this.form.submit()">
                    <option value="">All classes</option>
                    <?php foreach ($classes as $classId => $className): ?>
                        <option value="<?= (int) $classId ?>" <?= $classFilter === (int) $classId ? 'selected' : '' ?>><?= htmlspecialchars($className) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <noscript><button type="submit" class="btn">Filter</button></noscript>
        </form>
    <?php endif; ?>

    <table class="data-table">
        <thead><tr><th>Paper</th><th>Assigned by</th><th>Class</th><th>Student</th><th>Status</th><th>Score</th><th>Grade</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><a href="/assessment/teacher/papers/<?= (int) $row['paper_id'] ?>/results"><?= htmlspecialchars($row['paper_title']) ?></a></td>
                <td><?= htmlspecialchars($row['teacher_name']) ?></td>
                <td><?= htmlspecialchars($row['class_name']) ?></td>
                <td><?= htmlspecialchars($row['student_name']) ?></td>
                <td><?= htmlspecialchars($statusLabels[$row['status']] ?? $row['status']) ?></td>
                <td><?= $row['score'] !== null ? htmlspecialchars((string) $row['score']) . ' / ' . htmlspecialchars((string) $row['max']) : '—' ?></td>
                <td><?= htmlspecialchars($row['grade'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="7">No submissions yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
