<?php
/** @var array $branding */
$__title = 'Branding';
require __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../../models/Branding.php';

/** One colour field + its "use platform default" reset checkbox, since all six repeat the same shape. */
$colorField = static function (string $key, string $label, string $help, string $default) use ($branding): void {
    ?>
    <label><?= htmlspecialchars($label) ?> <span class="autosave-status">(<?= htmlspecialchars($help) ?>)</span>
        <input type="color" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($branding[$key] ?? $default) ?>">
    </label>
    <label><input type="checkbox" name="reset_<?= htmlspecialchars($key) ?>" value="1"> Use the platform default instead<?= $branding[$key] ? '' : ' (currently in use)' ?></label>
    <?php
};
?>
<div class="panel">
    <h1>Branding</h1>
    <p class="autosave-status">
        Puts your own house style on the platform for everyone who signs in - shown in the header on
        every page. Nothing here is required; leave it blank/default and the platform looks as it does
        now.
    </p>

    <form method="post" action="/assessment/admin/branding" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">

        <label>School name (shown in the header, used as the logo's alt text)
            <input type="text" name="school_name" maxlength="255" value="<?= htmlspecialchars($branding['school_name']) ?>" required>
        </label>

        <?php $colorField('primary_color', 'Primary colour', 'buttons, links, the header brand text', '#2952e3'); ?>
        <?php $colorField('accent_color', 'Accent colour', 'a highlight underline in the header', '#2952e3'); ?>

        <?php if ($branding['logo_drive_item_id']): ?>
            <p>
                Current logo:<br>
                <img src="/assessment/files/branding/logo?v=<?= urlencode((string) ($branding['updated_at'] ?? '')) ?>" alt="<?= htmlspecialchars($branding['school_name']) ?>" style="max-height:4rem;max-width:16rem;">
            </p>
            <label><input type="checkbox" name="remove_logo" value="1"> Remove the current logo (falls back to the school name as text)</label>
        <?php endif; ?>

        <label>Upload a new logo <span class="autosave-status">(PNG, JPEG or WebP - replaces the current one, if any; stored on OneDrive like every other file here)</span>
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp">
        </label>

        <h2>Marking colours</h2>
        <p class="autosave-status">
            Where each of these shows: a student's own typing/writing on a pdf-type paper (both to the
            student themselves, and as the read-only reference layer on marking/moderation); the default
            colour offered on the primary marking screen (a marker can still pick a different one per
            session); the default on the moderation screen; and the colour of a student's self-mark/
            reflection notes shown alongside marking.
        </p>
        <?php $colorField('student_work_color', "Student's own work", 'default ' . Branding::DEFAULT_STUDENT_WORK_COLOR, Branding::DEFAULT_STUDENT_WORK_COLOR); ?>
        <?php $colorField('teacher_marking_color', 'Primary marking', 'default ' . Branding::DEFAULT_TEACHER_MARKING_COLOR, Branding::DEFAULT_TEACHER_MARKING_COLOR); ?>
        <?php $colorField('teacher_moderation_color', 'Moderation', 'default ' . Branding::DEFAULT_TEACHER_MODERATION_COLOR, Branding::DEFAULT_TEACHER_MODERATION_COLOR); ?>
        <?php $colorField('self_marking_color', "Student self-marking notes", 'default ' . Branding::DEFAULT_SELF_MARKING_COLOR, Branding::DEFAULT_SELF_MARKING_COLOR); ?>

        <button type="submit" class="btn btn-primary">Save</button>
    </form>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
