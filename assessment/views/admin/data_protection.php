<?php
/** @var array $users search results, empty until a query is submitted */
/** @var array|null $retentionPreview ['total'=>int,'oldest'=>?string,'newest'=>?string], set once a cutoff date has been previewed */
$__title = 'Data protection';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>Data protection</h1>
    <p class="autosave-status">
        Tools to support UK GDPR / Data Protection Act 2018 requests - a subject access export, erasing
        someone's identity, and removing old submissions you no longer need to keep. This is a technical
        tool, not legal advice: your school is responsible for its own retention schedule and for
        deciding when each of these actually applies to a real request.
    </p>

    <?php if (isset($_GET['anonymized'])): ?>
        <p class="autosave-status">User #<?= (int) $_GET['anonymized'] ?> has been anonymized.</p>
    <?php elseif (isset($_GET['deleted'])): ?>
        <p class="autosave-status"><?= (int) $_GET['deleted'] ?> submission(s) deleted.</p>
    <?php endif; ?>

    <h2>Subject access export &amp; erasure</h2>
    <p>Find a user to download everything this app holds about them, or anonymize their identity (email and name - not their submissions; use the section below for those, on age grounds).</p>

    <form method="get" action="/assessment/admin/data-protection">
        <label>Search by name or email <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="e.g. Smith"></label>
        <button type="submit" class="btn">Search</button>
    </form>

    <?php if ($users): ?>
        <table class="data-table">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u['display_name']) ?></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td><?= htmlspecialchars(User::roleName($u['role'])) ?></td>
                    <td>
                        <a class="btn" href="/assessment/admin/data-protection/<?= (int) $u['id'] ?>/export">Export data (JSON)</a>
                        <form method="post" action="/assessment/admin/data-protection/<?= (int) $u['id'] ?>/anonymize" style="display:inline-flex;gap:0.4rem;align-items:center;" onsubmit="return confirm('Anonymize this user? Their email and name will be permanently replaced - this cannot be undone. Their submissions, marks and papers are untouched.');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                            <input type="text" name="confirm_name" placeholder="Type their name to confirm" required>
                            <button type="submit" class="btn btn-danger">Anonymize</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif (($_GET['q'] ?? '') !== ''): ?>
        <p>No users matched.</p>
    <?php endif; ?>

    <h2>Delete old submissions</h2>
    <p>
        Permanently deletes every submission started before a chosen date, and everything under it -
        answers, marks, self-marks, and in-PDF annotations. Unlike everything else in this app (which is
        carefully never actually deleted - see Classes &gt; Open test windows), this genuinely removes
        the rows. Preview first to see how many submissions and what date range would be affected.
    </p>

    <form method="get" action="/assessment/admin/data-protection">
        <input type="hidden" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
        <label>Preview submissions started before
            <input type="date" name="preview_before" value="<?= htmlspecialchars($_GET['preview_before'] ?? '') ?>" required>
        </label>
        <button type="submit" class="btn">Preview</button>
    </form>

    <?php if ($retentionPreview !== null): ?>
        <?php if ($retentionPreview['total'] === 0): ?>
            <p>No submissions started before <?= htmlspecialchars($_GET['preview_before']) ?>.</p>
        <?php else: ?>
            <p>
                <strong><?= (int) $retentionPreview['total'] ?> submission(s)</strong> would be deleted,
                started between <?= htmlspecialchars($retentionPreview['oldest']) ?> and
                <?= htmlspecialchars($retentionPreview['newest']) ?>.
            </p>
            <form method="post" action="/assessment/admin/data-protection/delete-old" onsubmit="return confirm('This permanently deletes ' + <?= (int) $retentionPreview['total'] ?> + ' submission(s) and everything under them. This cannot be undone. Continue?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                <input type="hidden" name="cutoff_date" value="<?= htmlspecialchars($_GET['preview_before']) ?>">
                <label>Type <strong>DELETE</strong> to confirm <input type="text" name="confirm_text" required></label>
                <button type="submit" class="btn btn-danger">Permanently delete these submissions</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
