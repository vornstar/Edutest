<?php
/** @var array $branding */
$__title = 'Branding';
require __DIR__ . '/../partials/header.php';
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

        <label>Primary colour <span class="autosave-status">(buttons, links, the header brand text)</span>
            <input type="color" name="primary_color" value="<?= htmlspecialchars($branding['primary_color'] ?? '#2952e3') ?>">
        </label>
        <label><input type="checkbox" name="reset_primary_color" value="1"> Use the platform default instead<?= $branding['primary_color'] ? '' : ' (currently in use)' ?></label>

        <label>Accent colour <span class="autosave-status">(a highlight underline in the header)</span>
            <input type="color" name="accent_color" value="<?= htmlspecialchars($branding['accent_color'] ?? '#2952e3') ?>">
        </label>
        <label><input type="checkbox" name="reset_accent_color" value="1"> Use the platform default instead<?= $branding['accent_color'] ? '' : ' (currently in use)' ?></label>

        <?php if ($branding['logo_filename']): ?>
            <p>
                Current logo:<br>
                <img src="<?= htmlspecialchars(asset_url('/assets/uploads/branding/' . $branding['logo_filename'])) ?>" alt="<?= htmlspecialchars($branding['school_name']) ?>" style="max-height:4rem;max-width:16rem;">
            </p>
            <label><input type="checkbox" name="remove_logo" value="1"> Remove the current logo (falls back to the school name as text)</label>
        <?php endif; ?>

        <label>Upload a new logo <span class="autosave-status">(PNG, JPEG or WebP - replaces the current one, if any)</span>
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp">
        </label>

        <button type="submit" class="btn btn-primary">Save</button>
    </form>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
