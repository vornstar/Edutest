<?php
/** @var array $entries */
$__title = 'Audit log';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>System audit log</h1>
    <form method="get" action="/assessment/admin/audit">
        <label>Entity type <input type="text" name="entity_type" value="<?= htmlspecialchars($_GET['entity_type'] ?? '') ?>" placeholder="e.g. submission, user"></label>
        <label>Entity ID <input type="number" name="entity_id" value="<?= htmlspecialchars($_GET['entity_id'] ?? '') ?>"></label>
        <button type="submit" class="btn">Search</button>
    </form>

    <table class="data-table">
        <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Before</th><th>After</th></tr></thead>
        <tbody>
        <?php foreach ($entries as $e): ?>
            <tr>
                <td><?= htmlspecialchars($e['created_at']) ?></td>
                <td><?= htmlspecialchars((string) $e['actor_id']) ?></td>
                <td><?= htmlspecialchars($e['action']) ?></td>
                <td><code><?= htmlspecialchars($e['before_json'] ?? '') ?></code></td>
                <td><code><?= htmlspecialchars($e['after_json'] ?? '') ?></code></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$entries): ?>
            <tr><td colspan="5">Enter an entity type and ID to view its history.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
