<?php
/** @var array $class */
/** @var array $students */
/** @var array $cells [studentId][assignmentId] => ['status','score','grade'] */
/** @var array $paperMeta keyed by assignment id: ['title','mode','max'] */
$__title = 'Markbook: ' . $class['name'];
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <div class="panel-header">
        <h1>Markbook: <?= htmlspecialchars($class['name']) ?></h1>
        <a class="btn" href="/assessment/teacher/classes/<?= (int) $class['id'] ?>/markbook/export">Export CSV</a>
    </div>
    <p class="autosave-status">
        Every paper assigned to this class, one column each - self-service/practice papers are marked
        as such. A blank cell means not yet attempted or not yet marked; a grade in brackets only shows
        if that paper has grade boundaries set, regardless of whether you've released them to students.
    </p>

    <div style="overflow-x:auto;">
    <table class="data-table">
        <thead>
            <tr>
                <th>Student</th>
                <?php foreach ($paperMeta as $meta): ?>
                    <th><?= htmlspecialchars($meta['title']) ?><?= $meta['mode'] === 'self_service' ? ' <span class="autosave-status">(self-service)</span>' : '' ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($students as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['display_name']) ?></td>
                <?php foreach (array_keys($paperMeta) as $assignmentId): $cell = $cells[(int) $s['id']][$assignmentId] ?? null; ?>
                    <td>
                        <?php if (!$cell || $cell['score'] === null): ?>
                            —
                        <?php else: ?>
                            <?= htmlspecialchars((string) $cell['score']) ?> / <?= htmlspecialchars((string) $paperMeta[$assignmentId]['max']) ?>
                            <?php if ($cell['grade'] !== null): ?> (<?= htmlspecialchars($cell['grade']) ?>)<?php endif; ?>
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        <?php if (!$students): ?>
            <tr><td colspan="<?= count($paperMeta) + 1 ?>">No students in this class.</td></tr>
        <?php endif; ?>
        <?php if (!$paperMeta): ?>
            <tr><td colspan="<?= count($paperMeta) + 1 ?>">No papers assigned to this class yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
