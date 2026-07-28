<?php
/** @var array $paper */
/** @var array $rows each: ['submission' => array, 'class_id' => int, 'class_name' => string, 'score' => float|null, 'grade' => string|null] */
/** @var float $maxTotal */
/** @var array $classes [class_id => class_name] every class this paper is assigned to */
/** @var int|null $classFilter */
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

    <?php if (count($classes) > 1): ?>
        <form method="get" action="/assessment/teacher/papers/<?= (int) $paper['id'] ?>/results" style="max-width:16rem;">
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
        <thead><tr><th>Class</th><th>Student</th><th>Status</th><th>Score</th><th>Grade</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): $s = $row['submission']; ?>
            <tr>
                <td><?= htmlspecialchars($row['class_name']) ?></td>
                <td><?= htmlspecialchars($s['student_name']) ?></td>
                <td><?= htmlspecialchars($statusLabels[$s['status']] ?? $s['status']) ?></td>
                <td><?= $row['score'] !== null ? htmlspecialchars((string) $row['score']) . ' / ' . htmlspecialchars((string) $maxTotal) : '—' ?></td>
                <td><?= htmlspecialchars($row['grade'] ?? '—') ?></td>
                <td>
                    <a class="btn" href="/assessment/teacher/marking/<?= (int) $s['id'] ?>"><?= $row['score'] !== null ? 'View/edit marks' : 'Mark' ?></a>
                    <a class="btn" href="/assessment/student/submissions/<?= (int) $s['id'] ?>" title="See exactly what this student sees - their marks, comments, and annotated script">View as student</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="6">No submissions yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
