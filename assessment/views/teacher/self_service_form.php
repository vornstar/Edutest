<?php
/** @var array $classes */
/** @var array $papers manageable papers */
/** @var array $groups */
$__title = 'Release for self-service';
require __DIR__ . '/../partials/header.php';

$groupNames = [];
foreach ($groups as $g) {
    $groupNames[(int) $g['id']] = $g['name'];
}
$byGroup = [];
foreach ($papers as $p) {
    $byGroup[$p['group_id'] ? (int) $p['group_id'] : 0][] = $p;
}
$orderedGroupIds = array_keys($byGroup);
usort($orderedGroupIds, static function (int $a, int $b) use ($groupNames): int {
    if ($a === 0) return 1;
    if ($b === 0) return -1;
    return strcasecmp($groupNames[$a] ?? '', $groupNames[$b] ?? '');
});
?>
<div class="panel">
    <h1>Release for self-service</h1>
    <p class="autosave-status">
        Pick a class and any number of papers - each becomes its own practice test that students can
        attempt and self-mark whenever they like, with no teacher marking step. They'll show up under
        "Practice papers" on the student's own test list, separate from anything you've formally
        assigned. You can still close or cancel one later from Open test windows, same as any other test.
    </p>

    <form method="post" action="/assessment/teacher/self-service">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">

        <label>Class
            <select name="class_id" required>
                <option value="">&mdash; choose a class &mdash;</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Due date <span class="autosave-status">(optional - leave blank for no deadline)</span>
            <input type="datetime-local" name="due_at">
        </label>

        <label><input type="checkbox" name="release_grade_boundaries" value="1"> Release grade boundaries immediately (if the paper has any set)</label>

        <h2>Papers</h2>
        <?php foreach ($orderedGroupIds as $gid): ?>
            <?php if (count($orderedGroupIds) > 1 || $gid !== 0): ?>
                <h3><?= $gid === 0 ? 'Ungrouped' : htmlspecialchars($groupNames[$gid] ?? 'Unknown group') ?></h3>
            <?php endif; ?>
            <?php foreach ($byGroup[$gid] as $p): ?>
                <label><input type="checkbox" name="paper_ids[]" value="<?= (int) $p['id'] ?>"> <?= htmlspecialchars($p['title']) ?> <span class="autosave-status">(<?= htmlspecialchars($p['type']) ?>)</span></label>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if (!$papers): ?>
            <p>No papers available to release yet.</p>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary">Release</button>
    </form>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
