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
/**
 * Matches the Select/Pen/Highlighter/Text/Circle/Erase buttons rendered above this
 * partial in mark_submission.php/moderation_review.php - kept here too so their
 * shortcuts can be managed from the same dropdown. 'delete' is the erase tool's
 * data-tool value - unchanged from when it was labelled "Delete selected", so any
 * shortcut already set for it keeps working.
 */
$__toolShortcutTargets = [
    ['select', 'Select'],
    ['pen', 'Pen'],
    ['highlighter', 'Highlighter'],
    ['text', 'Text'],
    ['circle', 'Circle'],
    ['delete', 'Erase'],
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
        <p class="autosave-status" style="margin:0 0 0.5rem;">A tool shortcut selects that tool, same as clicking its button. A stamp shortcut arms the stamp - click (or tap) the script to place it. Leave a box blank to clear that shortcut.</p>
        <form method="post" action="/assessment/teacher/stamps/shortcuts" class="shortcuts-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
            <p class="autosave-status" style="margin:0 0 0.25rem;"><strong>Tools</strong></p>
            <?php foreach ($__toolShortcutTargets as [$tool, $title]): ?>
                <div class="shortcut-row">
                    <span><?= htmlspecialchars($title) ?></span>
                    <input type="text" name="shortcuts[tool][<?= htmlspecialchars($tool) ?>]" maxlength="1" size="1" value="<?= htmlspecialchars($shortcuts['tool'][$tool] ?? '') ?>" placeholder="key">
                </div>
            <?php endforeach; ?>
            <p class="autosave-status" style="margin:0.5rem 0 0.25rem;"><strong>Stamps</strong></p>
            <?php foreach ($__builtInStamps as [$label, $title]): ?>
                <div class="shortcut-row">
                    <span><?= htmlspecialchars($title) ?></span>
                    <input type="text" name="shortcuts[stamp][<?= htmlspecialchars($label) ?>]" maxlength="1" size="1" value="<?= htmlspecialchars($shortcuts['stamp'][$label] ?? '') ?>" placeholder="key">
                </div>
            <?php endforeach; ?>
            <?php foreach ($customStamps as $s): ?>
                <div class="shortcut-row">
                    <span><?= htmlspecialchars($s['label']) ?></span>
                    <?php /* Keyed by id, not label - CustomStamp::create() doesn't enforce unique labels per user, so two custom stamps could share a label and collide if this were label-keyed. StampController::setShortcuts() resolves the id back to a label server-side. */ ?>
                    <input type="text" name="shortcuts[custom][<?= (int) $s['id'] ?>]" maxlength="1" size="1" value="<?= htmlspecialchars($shortcuts['stamp'][$s['label']] ?? '') ?>" placeholder="key">
                </div>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-primary">Save shortcuts</button>
        </form>
        <?php foreach ($customStamps as $s): ?>
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
