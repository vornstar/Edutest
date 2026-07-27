<?php
/** @var array $__user */
$roleId = $__user['role'];
?>
<?php if ($roleId === User::ROLE_STUDENT): ?>
    <a href="/assessment/student">My tests</a>
<?php endif; ?>

<?php if (in_array($roleId, User::TEACHER_PORTAL_ROLES, true)): ?>
    <a href="/assessment/teacher/papers">Papers</a>
    <a href="/assessment/teacher/classes">Classes</a>
    <a href="/assessment/teacher/open-tests">Open tests</a>
    <a href="/assessment/teacher/marking">Marking</a>
    <a href="/assessment/teacher/moderation/queue">Moderation</a>
    <a href="/assessment/data/department-results">Subject results</a>
    <?php if ($roleId === User::ROLE_SUBJECT_LEADER): ?>
        <a href="/assessment/teacher/moderation/flagged">Flagged</a>
    <?php endif; ?>
<?php endif; ?>

<?php if ($roleId === User::ROLE_DATA): ?>
    <a href="/assessment/data">Reports</a>
<?php endif; ?>

<?php if ($roleId === User::ROLE_ADMIN): ?>
    <a href="/assessment/data">Reports</a>
    <a href="/assessment/data/department-results">Subject results</a>
    <a href="/assessment/admin/users">Users</a>
    <a href="/assessment/admin/subjects">Subjects</a>
    <a href="/assessment/admin/audit">Audit log</a>
    <a href="/assessment/admin/onedrive-lookup">OneDrive setup</a>
<?php endif; ?>
