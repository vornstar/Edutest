<?php
/** @var array $result */
$__title = 'Moderation result';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>Moderation complete</h1>
    <p>Variance from primary marker: <strong><?= htmlspecialchars((string) $result['variance']) ?></strong> marks.</p>
    <?php if ($result['exceeded_tolerance']): ?>
        <p class="alert">Variance exceeds the configured tolerance. The Subject Leader has been flagged for review.</p>
    <?php else: ?>
        <p>Within tolerance &mdash; no further action required.</p>
    <?php endif; ?>
    <a class="btn" href="/assessment/teacher/moderation/queue">Back to moderation queue</a>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
