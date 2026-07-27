<?php
/** @var array $users */
$__title = 'User management';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>Users &amp; roles</h1>
    <table class="data-table">
        <thead><tr><th>Name</th><th>Email</th><th>Signed in?</th><th>Current role</th><th>Change role</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['display_name']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= $u['site_user_id'] ? 'Yes' : 'Pending first sign-in' ?></td>
                <td><?= htmlspecialchars(User::roleName($u['role'])) ?></td>
                <td>
                    <form method="post" action="/assessment/admin/users/<?= (int) $u['id'] ?>/role">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                        <select name="role">
                            <?php foreach (User::ROLES as $role): ?>
                                <option value="<?= htmlspecialchars($role) ?>" <?= $role === $u['role'] ? 'selected' : '' ?>><?= htmlspecialchars($role) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn">Update</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Add a user from your tenant</h2>
    <p>Pre-provision a colleague by email and assign their role now. It takes effect the moment they first sign in with Microsoft.</p>
    <form method="post" action="/assessment/admin/users/add">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
        <label>Email <input type="email" name="email" required></label>
        <label>Display name <input type="text" name="display_name" placeholder="Optional - filled in from Microsoft on first sign-in if left blank"></label>
        <label>Role
            <select name="role">
                <?php foreach (User::ROLES as $role): ?>
                    <option value="<?= htmlspecialchars($role) ?>"><?= htmlspecialchars($role) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="btn btn-primary">Add user</button>
    </form>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
