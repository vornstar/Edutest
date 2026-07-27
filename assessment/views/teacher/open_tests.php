<?php
/** @var array $assignments */
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
    </p>

    <table class="data-table">
        <thead><tr><th>Paper</th><th>Class</th><th>Due</th><th>Progress</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($assignments as $a): $p = $progress[(int) $a['id']]; $isClosed = !empty($a['closed_at']); ?>
            <tr>
                <td><?= htmlspecialchars($a['paper_title']) ?></td>
                <td><?= htmlspecialchars($a['class_name']) ?></td>
                <td><?= htmlspecialchars($a['due_at'] ?? '—') ?></td>
                <td><?= (int) $p['started'] ?>/<?= (int) $p['roster'] ?> started &middot; <?= (int) $p['completed'] ?> submitted or further</td>
                <td><?= $isClosed ? 'Closed (' . htmlspecialchars($a['closed_at']) . ')' : 'Open' ?></td>
                <td>
                    <form method="post" action="/assessment/teacher/assignments/<?= (int) $a['id'] ?>/<?= $isClosed ? 'reopen' : 'close' ?>" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                        <button type="submit" class="btn <?= $isClosed ? '' : 'btn-danger' ?>"><?= $isClosed ? 'Reopen' : 'Close now' ?></button>
                    </form>
                    <a class="btn" href="/assessment/teacher/papers/<?= (int) $a['paper_id'] ?>/results">Results</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$assignments): ?>
            <tr><td colspan="6">No tests assigned to a class yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
