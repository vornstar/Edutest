<?php
/** @var array $users */
/** @var string $query */
/** @var array $subjects */
$__title = 'User management';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>Users &amp; roles</h1>
    <form method="get" action="/assessment/admin/users">
        <label>Search by name
            <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="e.g. Smith">
        </label>
        <button type="submit" class="btn">Search</button>
        <?php if ($query !== ''): ?>
            <a class="btn" href="/assessment/admin/users">Clear</a>
        <?php endif; ?>
    </form>
    <table class="data-table">
        <thead><tr><th>Name</th><th>Email</th><th>Signed in?</th><th>Current role</th><th>Change role</th><th>Subject</th></tr></thead>
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
                <td>
                    <form method="post" action="/assessment/admin/users/<?= (int) $u['id'] ?>/managed-subject" style="display:flex;gap:0.4rem;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
                        <select name="managed_subject">
                            <option value="">&mdash; none &mdash;</option>
                            <?php foreach ($subjects as $s): ?>
                                <option value="<?= htmlspecialchars($s['name']) ?>" <?= strcasecmp((string) $s['name'], (string) ($u['managed_subject'] ?? '')) === 0 ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn">Set</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="autosave-status">
        Subject applies to Teacher and Subject Leader alike: it's what scopes which papers/tests they can
        see (own papers plus anyone else's in the same subject). A Subject Leader additionally gets
        manage/delete authority over the whole subject's papers, not just their own.
        Manage the list of subjects on offer in <a href="/assessment/admin/subjects">Admin &gt; Subjects</a>.
    </p>

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
