<?php
/** @var string $configuredLink */
/** @var array|null $testResult */
/** @var string|null $testError */
$__title = 'OneDrive setup';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>OneDrive setup</h1>
    <p>The assessment platform stores every exam paper, mark scheme, and scanned script in one shared
        OneDrive/SharePoint folder, using each signed-in person's own Microsoft permissions rather than
        a special app-only permission (which this tenant hasn't been able to grant admin consent for).
        This means the setup is a normal folder-sharing action, not an Azure Portal step.</p>

    <h2>One-time setup</h2>
    <ol>
        <li>In OneDrive or SharePoint, create (or pick) a folder to hold all assessment files - e.g. "Assessments".</li>
        <li>Right-click it → <strong>Share</strong>.</li>
        <li>Set the link to <strong>"People in [your organisation] with the link"</strong> and <strong>Can edit</strong> (not "can view" - students need to be able to upload scans, and teachers need to upload/replace papers).</li>
        <li>Copy that link.</li>
        <li>Open <code>.env.php</code> at your site root and set:
            <br><code>ASSESSMENT_ONEDRIVE_FOLDER_LINK=&lt;the link you copied&gt;</code>
        </li>
        <li>Save, then reload this page - it'll confirm the link resolves correctly below.</li>
    </ol>

    <h2>Current status</h2>
    <?php if ($configuredLink === ''): ?>
        <p class="alert">ASSESSMENT_ONEDRIVE_FOLDER_LINK is not set yet in .env.php.</p>
    <?php elseif ($testError): ?>
        <p class="alert">Could not resolve the configured link: <?= htmlspecialchars($testError) ?></p>
        <p>Common causes: the link's sharing permission isn't set to "People in the organisation with the link can edit", or the link has been changed/revoked since it was copied into <code>.env.php</code>.</p>
    <?php elseif ($testResult): ?>
        <p><strong>Working.</strong> Resolved to folder "<?= htmlspecialchars($testResult['name']) ?>"<?= $testResult['webUrl'] ? ' (<a href="' . htmlspecialchars($testResult['webUrl']) . '" target="_blank">open in OneDrive</a>)' : '' ?>.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
