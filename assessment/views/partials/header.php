<?php
/** @var array|null $__user Set by AuthController before including views; falls back to session. */
$__user = $__user ?? (AuthController::currentUser() ?? null);
$__title = $__title ?? 'Assessment Platform';

require_once __DIR__ . '/../../models/Branding.php';
$__branding = Branding::get();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($__title) ?></title>
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
<script src="<?= asset_url('/assets/js/nav.js') ?>" defer></script>
<?php if ($__branding['primary_color'] || $__branding['accent_color']): ?>
<style>
:root {
<?php if ($__branding['primary_color']): ?>
    --color-primary: <?= htmlspecialchars($__branding['primary_color']) ?>;
<?php endif; ?>
<?php if ($__branding['accent_color']): ?>
    --color-accent: <?= htmlspecialchars($__branding['accent_color']) ?>;
<?php endif; ?>
}
</style>
<?php endif; ?>
</head>
<body>
<header class="app-header<?= $__branding['accent_color'] ? ' has-accent' : '' ?>">
    <a class="brand" href="/assessment/">
        <?php if ($__branding['logo_filename']): ?>
            <img class="brand-logo" src="<?= htmlspecialchars(asset_url('/assets/uploads/branding/' . $__branding['logo_filename'])) ?>" alt="<?= htmlspecialchars($__branding['school_name']) ?>">
        <?php else: ?>
            <?= htmlspecialchars($__branding['school_name']) ?>
        <?php endif; ?>
    </a>
    <?php if ($__user): ?>
    <nav class="main-nav">
        <?php require __DIR__ . '/nav.php'; ?>
    </nav>
    <div class="user-chip">
        <span><?= htmlspecialchars($__user['display_name']) ?> &middot; <?= htmlspecialchars(User::roleName($__user['role'])) ?></span>
        <a href="/assessment/logout">Sign out</a>
    </div>
    <?php endif; ?>
</header>
<main class="app-main">
