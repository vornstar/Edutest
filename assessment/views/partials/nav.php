<?php
/** @var array $__user */
$roleId = $__user['role'];
?>
<?php if ($roleId === User::ROLE_STUDENT): ?>
    <a href="/assessment/student">My tests</a>
<?php endif; ?>

<?php if (in_array($roleId, [User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER], true)): ?>
    <a href="/assessment/teacher/papers">Papers</a>
    <a href="/assessment/teacher/classes">Classes</a>
    <a href="/assessment/teacher/marking">Marking</a>
    <a href="/assessment/teacher/moderation/queue">Moderation</a>
    <?php if ($roleId === User::ROLE_SUBJECT_LEADER): ?>
        <a href="/assessment/teacher/moderation/flagged">Flagged</a>
        <a href="/assessment/data">Reports</a>
    <?php endif; ?>
<?php endif; ?>

<?php if ($roleId === User::ROLE_DATA): ?>
    <a href="/assessment/data">Reports</a>
<?php endif; ?>

<?php if ($roleId === User::ROLE_ADMIN): ?>
    <a href="/assessment/admin/users">Users</a>
    <a href="/assessment/admin/audit">Audit log</a>
    <a href="/assessment/admin/onedrive-lookup">OneDrive setup</a>
    <a href="/assessment/data">Reports</a>
<?php endif; ?>
