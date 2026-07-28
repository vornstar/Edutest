<?php
/** @var array $customStamps each: ['id' => int, 'label' => string] - included from mark_submission.php/moderation_review.php, expects $customStamps to already be in scope. */
/** @var array $shortcuts {tool: array<string,string>, stamp: array<string,string>} label => single-character key, this marker's own - see StampShortcut */
$__builtInStamps = [
    ["\u{2713}", 'Tick'],
    ["\u{2717}", 'Cross'],
    ['SEEN', 'SEEN'],
    ['NE', 'NE'],
    ['LC', 'LC'],
    ['BOD', 'BOD'],
];
/** Matches the Pen/Highlighter/Text/Circle/Delete buttons rendered above this partial in mark_submission.php/moderation_review.php - kept here too so their shortcuts can be managed from the same dropdown. */
$__toolShortcutTargets = [
    ['pen', 'Pen'],
    ['highlighter', 'Highlighter'],
    ['text', 'Text'],
    ['circle', 'Circle'],
    ['delete', 'Delete selected'],
];
?>
<span class="tool-divider" aria-hidden="true"></span>
<?php foreach ($__builtInStamps as [$label, $title]): $key = $shortcuts['stamp'][$label] ?? null; ?>
    <button type="button" data-tool="stamp" data-stamp="<?= htmlspecialchars($label) ?>"
            <?= $key ? 'data-shortcut="' . htmlspecialchars($key) . '"' : '' ?>
            title="<?= htmlspecialchars($title) ?><?= $key ? ' (shortcut: ' . htmlspecialchars(strtoupper($key)) . ')' : '' ?>"><?= htmlspecialchars($label) ?><?= $key ? ' <sup>' . htmlspecialchars(strtoupper($key)) . '</sup>' : '' ?></button>
<?php endforeach; ?>
<?php foreach ($customStamps as $s): $key = $shortcuts['stamp'][$s['label']] ?? null; ?>
    <button type="button" data-tool="stamp" data-stamp="<?= htmlspecialchars($s['label']) ?>"
            <?= $key ? 'data-shortcut="' . htmlspecialchars($key) . '"' : '' ?>
            title="<?= $key ? 'Shortcut: ' . htmlspecialchars(strtoupper($key)) : '' ?>"><?= htmlspecialchars($s['label']) ?><?= $key ? ' <sup>' . htmlspecialchars(strtoupper($key)) . '</sup>' : '' ?></button>
<?php endforeach; ?>
<details class="nav-dropdown">
    <summary title="Set your own keyboard shortcuts for tools and stamps">Keyboard shortcuts</summary>
    <div class="nav-dropdown-menu stamp-manage-menu">
        <p class="autosave-status" style="margin:0 0 0.5rem;">A tool shortcut selects that tool, same as clicking its button. A stamp shortcut arms the stamp - click (or tap) the script to place it.</p>
        <p class="autosave-status" style="margin:0 0 0.25rem;"><strong>Tools</strong></p>
        <?php foreach ($__toolShortcutTargets as [$tool, $title]): ?>
            <form method="post" action="/assessment/teacher/stamps/shortcut" class="stamp-shortcut-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                <input type="hidden" name="target_type" value="tool">
                <input type="hidden" name="stamp_label" value="<?= htmlspecialchars($tool) ?>">
                <span><?= htmlspecialchars($title) ?></span>
                <input type="text" name="shortcut_key" maxlength="1" size="1" value="<?= htmlspecialchars($shortcuts['tool'][$tool] ?? '') ?>" placeholder="key">
                <button type="submit" title="Save">&check;</button>
            </form>
        <?php endforeach; ?>
        <p class="autosave-status" style="margin:0.5rem 0 0.25rem;"><strong>Stamps</strong></p>
        <?php foreach ($__builtInStamps as [$label, $title]): ?>
            <form method="post" action="/assessment/teacher/stamps/shortcut" class="stamp-shortcut-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                <input type="hidden" name="target_type" value="stamp">
                <input type="hidden" name="stamp_label" value="<?= htmlspecialchars($label) ?>">
                <span><?= htmlspecialchars($title) ?></span>
                <input type="text" name="shortcut_key" maxlength="1" size="1" value="<?= htmlspecialchars($shortcuts['stamp'][$label] ?? '') ?>" placeholder="key">
                <button type="submit" title="Save">&check;</button>
            </form>
        <?php endforeach; ?>
        <?php foreach ($customStamps as $s): ?>
            <form method="post" action="/assessment/teacher/stamps/shortcut" class="stamp-shortcut-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                <input type="hidden" name="target_type" value="stamp">
                <input type="hidden" name="stamp_label" value="<?= htmlspecialchars($s['label']) ?>">
                <span><?= htmlspecialchars($s['label']) ?></span>
                <input type="text" name="shortcut_key" maxlength="1" size="1" value="<?= htmlspecialchars($shortcuts['stamp'][$s['label']] ?? '') ?>" placeholder="key">
                <button type="submit" title="Save">&check;</button>
            </form>
            <form method="post" action="/assessment/teacher/stamps/<?= (int) $s['id'] ?>/delete" onsubmit="return confirm('Remove the &quot;<?= htmlspecialchars(addslashes($s['label'])) ?>&quot; stamp?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                <span>Remove &quot;<?= htmlspecialchars($s['label']) ?>&quot;</span>
                <button type="submit" title="Remove">&times;</button>
            </form>
        <?php endforeach; ?>
        <form method="post" action="/assessment/teacher/stamps/add" class="stamp-add-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
            <input type="text" name="label" maxlength="20" placeholder="New stamp" required>
            <button type="submit">Add</button>
        </form>
    </div>
</details>
