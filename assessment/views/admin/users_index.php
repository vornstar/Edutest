<?php
/** @var array $users */
$__title = 'User management';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>Users &amp; roles</h1>
    <table class="data-table">
        <thead><tr><th>Name</th><th>Email</th><th>Current role</th><th>Change role</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['display_name']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars(User::roleName((int) $u['role_id'])) ?></td>
                <td>
                    <form method="post" action="/assessment/admin/users/<?= (int) $u['id'] ?>/role">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                        <select name="role_id">
                            <?php foreach (User::ROLE_NAMES as $id => $name): ?>
                                <option value="<?= $id ?>" <?= $id === (int) $u['role_id'] ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn">Update</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
