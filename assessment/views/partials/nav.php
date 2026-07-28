<?php
/** @var array $__user */
$roleId = $__user['role'];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
/** True if any link inside a dropdown matches the current page, so it renders "open" (and highlighted) on load instead of requiring a click first. */
$hasActive = static function (array $hrefs) use ($path): bool {
    foreach ($hrefs as $href) {
        if ($path === $href || str_starts_with($path, $href . '/')) {
            return true;
        }
    }
    return false;
};
?>
<?php if ($roleId === User::ROLE_STUDENT): ?>
    <a href="/assessment/student">My tests</a>
<?php endif; ?>

<?php if (in_array($roleId, User::TEACHER_PORTAL_ROLES, true)): ?>
    <a href="/assessment/teacher/papers">Papers</a>

    <?php $hrefs = ['/assessment/teacher/classes', '/assessment/teacher/open-tests']; ?>
    <details class="nav-dropdown" <?= $hasActive($hrefs) ? 'open' : '' ?>>
        <summary>Classes</summary>
        <div class="nav-dropdown-menu">
            <a href="/assessment/teacher/classes">Classes</a>
            <a href="/assessment/teacher/open-tests">Open test windows</a>
        </div>
    </details>

    <?php $hrefs = ['/assessment/teacher/marking', '/assessment/teacher/moderation']; ?>
    <details class="nav-dropdown" <?= $hasActive($hrefs) ? 'open' : '' ?>>
        <summary>Marking</summary>
        <div class="nav-dropdown-menu">
            <a href="/assessment/teacher/marking">Marking</a>
            <a href="/assessment/teacher/moderation/queue">Moderation</a>
            <?php if ($roleId === User::ROLE_SUBJECT_LEADER): ?>
                <a href="/assessment/teacher/moderation/flagged">Flagged variances</a>
            <?php endif; ?>
        </div>
    </details>

    <?php $hrefs = ['/assessment/data']; ?>
    <details class="nav-dropdown" <?= $hasActive($hrefs) ? 'open' : '' ?>>
        <summary>Results</summary>
        <div class="nav-dropdown-menu">
            <a href="/assessment/data/department-results">Subject results</a>
            <?php if (in_array($roleId, [User::ROLE_SUBJECT_LEADER, User::ROLE_ADMIN], true)): ?>
                <a href="/assessment/data">Institution reports</a>
            <?php endif; ?>
        </div>
    </details>
<?php endif; ?>

<?php if ($roleId === User::ROLE_DATA): ?>
    <a href="/assessment/data">Reports</a>
<?php endif; ?>

<?php if ($roleId === User::ROLE_ADMIN): ?>
    <?php $hrefs = ['/assessment/admin']; ?>
    <details class="nav-dropdown" <?= $hasActive($hrefs) ? 'open' : '' ?>>
        <summary>Admin</summary>
        <div class="nav-dropdown-menu">
            <a href="/assessment/admin/users">Users</a>
            <a href="/assessment/admin/subjects">Subjects</a>
            <a href="/assessment/admin/branding">Branding</a>
            <a href="/assessment/admin/audit">Audit log</a>
            <a href="/assessment/admin/onedrive-lookup">OneDrive setup</a>
        </div>
    </details>
<?php endif; ?>
