<?php
/** @var array $assignments */
/** @var array $cancelledAssignments */
/** @var array $progress keyed by assignment id: ['roster'=>int,'started'=>int,'completed'=>int] */
$__title = 'Open tests';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>Test windows</h1>
    <p class="autosave-status">
        Every test you've assigned to a class. Close a window early to stop any further work being
        accepted on it (e.g. once time's up) - students already mid-test are locked out immediately,
        the same as anyone who hasn't started. Reopen one any time, e.g. for an agreed extension.
        Cancelling removes it from the class entirely instead - it disappears from students' lists (and
        from Teams if it was pushed there), without deleting any submissions, marks or annotations
        underneath it. A cancelled test can be restored from Deleted tests below. Releasing grades lets
        every student on this test see their own resolved grade (if the paper has grade boundaries set)
        and the boundary table it came from.
    </p>

    <?php if (isset($_GET['self_service_released'])): ?>
        <p class="autosave-status">Released <?= (int) $_GET['self_service_released'] ?> paper(s) for self-service.</p>
    <?php endif; ?>

    <table class="data-table">
        <thead><tr><th>Paper</th><th>Mode</th><th>Class</th><th>Due</th><th>Progress</th><th>Status</th><th>Grades</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($assignments as $a): $p = $progress[(int) $a['id']]; $isClosed = !empty($a['closed_at']); $gradesReleased = !empty($a['grade_released_at']); ?>
            <tr>
                <td><?= htmlspecialchars($a['paper_title']) ?></td>
                <td><?= ($a['mode'] ?? 'assigned') === 'self_service' ? 'Self-service' : 'Assigned' ?></td>
                <td><?= htmlspecialchars($a['class_name']) ?></td>
                <td><?= htmlspecialchars($a['due_at'] ?? '—') ?></td>
                <td><?= (int) $p['started'] ?>/<?= (int) $p['roster'] ?> started &middot; <?= (int) $p['completed'] ?> submitted or further</td>
                <td><?= $isClosed ? 'Closed (' . htmlspecialchars($a['closed_at']) . ')' : 'Open' ?></td>
                <td><?= $gradesReleased ? 'Released' : 'Not released' ?></td>
                <td>
                    <form method="post" action="/assessment/teacher/assignments/<?= (int) $a['id'] ?>/<?= $isClosed ? 'reopen' : 'close' ?>" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                        <button type="submit" class="btn <?= $isClosed ? '' : 'btn-danger' ?>"><?= $isClosed ? 'Reopen' : 'Close now' ?></button>
                    </form>
                    <form method="post" action="/assessment/teacher/assignments/<?= (int) $a['id'] ?>/<?= $gradesReleased ? 'unrelease-grades' : 'release-grades' ?>" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                        <button type="submit" class="btn"><?= $gradesReleased ? 'Unrelease grades' : 'Release grades' ?></button>
                    </form>
                    <a class="btn" href="/assessment/teacher/papers/<?= (int) $a['paper_id'] ?>/results">Results</a>
                    <form method="post" action="/assessment/teacher/assignments/<?= (int) $a['id'] ?>/cancel" style="display:inline" onsubmit="return confirm('Cancel this test? It will disappear from students\' lists and be removed from Teams if it was pushed there. Nothing is deleted - you can restore it from Deleted tests.');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                        <button type="submit" class="btn btn-danger">Cancel test</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$assignments): ?>
            <tr><td colspan="8">No tests assigned to a class yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="panel">
    <h1>Deleted tests</h1>
    <p class="autosave-status">
        Tests you've cancelled. Nothing underneath them was deleted - restoring one brings it straight
        back for students exactly as it was. Note: restoring does not re-create a Teams assignment that
        was removed when it was cancelled.
    </p>

    <table class="data-table">
        <thead><tr><th>Paper</th><th>Class</th><th>Cancelled</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cancelledAssignments as $a): ?>
            <tr>
                <td><?= htmlspecialchars($a['paper_title']) ?></td>
                <td><?= htmlspecialchars($a['class_name']) ?></td>
                <td><?= htmlspecialchars($a['cancelled_at']) ?></td>
                <td>
                    <form method="post" action="/assessment/teacher/assignments/<?= (int) $a['id'] ?>/restore" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                        <button type="submit" class="btn">Restore</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$cancelledAssignments): ?>
            <tr><td colspan="4">No cancelled tests.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
