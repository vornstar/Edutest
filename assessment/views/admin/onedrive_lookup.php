<?php
/** @var array $candidates */
/** @var array $errors */
$__title = 'OneDrive setup';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>Find your shared drive ID</h1>
    <p>The assessment platform stores every exam paper, mark scheme, and scanned script in one shared
        Microsoft 365 drive, so a teacher's upload can be read by the right students and other markers.
        Pick one option below and copy its ID into <code>ASSESSMENT_ONEDRIVE_DRIVE_ID</code> in your <code>.env.php</code> file.</p>

    <?php if ($errors): ?>
        <div class="alert">
            <?php foreach ($errors as $e): ?>
                <p><?= htmlspecialchars($e) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <table class="data-table">
        <thead><tr><th>Option</th><th>Drive ID</th><th>Notes</th></tr></thead>
        <tbody>
        <?php foreach ($candidates as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['label']) ?></td>
                <td><code><?= htmlspecialchars((string) ($c['id'] ?? 'unavailable')) ?></code></td>
                <td><?= htmlspecialchars($c['note']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$candidates): ?>
            <tr><td colspan="3">Nothing found. See the messages above, or make sure you're a member of at least one Microsoft Team.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h2>What to do with the ID</h2>
    <ol>
        <li>Pick one row above - if you already have (or create) a Team just for staff/assessment storage, use that one; otherwise the whole-organisation option works fine.</li>
        <li>Copy the text in its "Drive ID" column.</li>
        <li>Open <code>.env.php</code> at your site root and set:
            <br><code>ASSESSMENT_ONEDRIVE_DRIVE_ID=&lt;the id you copied&gt;</code>
        </li>
        <li>Save. No restart is needed - the next request picks it up.</li>
    </ol>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
