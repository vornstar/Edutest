<?php
/** @var array|null $__user Set by AuthController before including views; falls back to session. */
$__user = $__user ?? (AuthController::currentUser() ?? null);
$__title = $__title ?? 'Assessment Platform';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($__title) ?></title>
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
<script src="<?= asset_url('/assets/js/nav.js') ?>" defer></script>
</head>
<body>
<header class="app-header">
    <a class="brand" href="/assessment/">Assessment Platform</a>
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
