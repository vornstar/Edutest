<?php
/** @var array $customStamps each: ['id' => int, 'label' => string] - included from mark_submission.php/moderation_review.php, expects $customStamps to already be in scope. */
?>
<span class="tool-divider" aria-hidden="true"></span>
<button type="button" data-tool="stamp" data-stamp="&#10003;" title="Tick">&#10003;</button>
<button type="button" data-tool="stamp" data-stamp="&#10007;" title="Cross">&#10007;</button>
<button type="button" data-tool="stamp" data-stamp="SEEN">SEEN</button>
<button type="button" data-tool="stamp" data-stamp="NE">NE</button>
<button type="button" data-tool="stamp" data-stamp="LC">LC</button>
<button type="button" data-tool="stamp" data-stamp="BOD">BOD</button>
<?php foreach ($customStamps as $s): ?>
    <button type="button" data-tool="stamp" data-stamp="<?= htmlspecialchars($s['label']) ?>"><?= htmlspecialchars($s['label']) ?></button>
<?php endforeach; ?>
<details class="nav-dropdown">
    <summary title="Add or remove your own stamps">Manage stamps</summary>
    <div class="nav-dropdown-menu stamp-manage-menu">
        <?php if ($customStamps): ?>
            <?php foreach ($customStamps as $s): ?>
                <form method="post" action="/assessment/teacher/stamps/<?= (int) $s['id'] ?>/delete" onsubmit="return confirm('Remove the &quot;<?= htmlspecialchars(addslashes($s['label'])) ?>&quot; stamp?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                    <span><?= htmlspecialchars($s['label']) ?></span>
                    <button type="submit" title="Remove">&times;</button>
                </form>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="autosave-status">No custom stamps yet.</p>
        <?php endif; ?>
        <form method="post" action="/assessment/teacher/stamps/add" class="stamp-add-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
            <input type="text" name="label" maxlength="20" placeholder="New stamp" required>
            <button type="submit">Add</button>
        </form>
    </div>
</details>
