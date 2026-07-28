<?php
/** @var array $papers */
/** @var array $user */
/** @var array $groups */
$__title = 'Papers';
require __DIR__ . '/../partials/header.php';

$groupNames = [];
foreach ($groups as $g) {
    $groupNames[(int) $g['id']] = $g['name'];
}

// Bucket papers by group (0 = ungrouped), then order buckets alphabetically
// by group name with ungrouped last - visually "grouping" the list is the
// whole point of this feature, not just a filter/column.
$byGroup = [];
foreach ($papers as $p) {
    $gid = $p['group_id'] ? (int) $p['group_id'] : 0;
    $byGroup[$gid][] = $p;
}
$orderedGroupIds = array_keys($byGroup);
usort($orderedGroupIds, static function (int $a, int $b) use ($groupNames): int {
    if ($a === 0) return 1;
    if ($b === 0) return -1;
    return strcasecmp($groupNames[$a] ?? '', $groupNames[$b] ?? '');
});

$renderPapersTable = static function (array $papers) use ($user): void {
    ?>
    <table class="data-table">
        <thead><tr><th>Title</th><th>Subject</th><th>Type</th><th>Status</th><th>Owner</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($papers as $p): ?>
            <?php $canManage = PaperController::canManagePaper($user, $p); ?>
            <tr>
                <td><?= htmlspecialchars($p['title']) ?></td>
                <td><?= htmlspecialchars($p['subject'] ?? '—') ?></td>
                <td><?= htmlspecialchars($p['type']) ?></td>
                <td><?= htmlspecialchars($p['status']) ?></td>
                <td><?= (int) $p['created_by'] === (int) $user['id'] ? 'You' : 'Colleague' ?></td>
                <td>
                    <a href="/assessment/teacher/papers/<?= (int) $p['id'] ?>"><?= $canManage ? 'Manage' : 'View' ?></a>
                    <?php if ($canManage): ?>
                        &middot;
                        <a href="/assessment/teacher/papers/<?= (int) $p['id'] ?>/assign">Assign</a>
                    <?php endif; ?>
                    &middot;
                    <a href="/assessment/teacher/papers/<?= (int) $p['id'] ?>/results">Results</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
};
?>
<div class="panel">
    <div class="panel-header">
        <h1>Papers</h1>
        <div>
            <a class="btn" href="/assessment/teacher/groups">Groups</a>
            <a class="btn btn-primary" href="/assessment/teacher/papers/create">New paper</a>
        </div>
    </div>

    <?php if (!$papers): ?>
        <p>No papers yet.</p>
    <?php endif; ?>

    <?php $showHeadings = count($orderedGroupIds) > 1 || ($orderedGroupIds && $orderedGroupIds[0] !== 0); ?>
    <?php foreach ($orderedGroupIds as $gid): ?>
        <?php if ($showHeadings): ?>
            <h2><?= $gid === 0 ? 'Ungrouped' : htmlspecialchars($groupNames[$gid] ?? 'Unknown group') ?></h2>
        <?php endif; ?>
        <?php $renderPapersTable($byGroup[$gid]); ?>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
