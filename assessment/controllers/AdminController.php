<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/AuditLog.php';
require_once __DIR__ . '/../models/ClassRoster.php';
require_once __DIR__ . '/../services/GraphApiClient.php';

/**
 * Full system access: global role management and system audit review (SRS 3.2, Admin row).
 */
final class AdminController
{
    public static function users(): void
    {
        AuthController::requireRole([User::ROLE_ADMIN]);
        $users = User::all();
        require __DIR__ . '/../views/admin/users_index.php';
    }

    public static function setRole(int $userId): void
    {
        $admin = AuthController::requireRole([User::ROLE_ADMIN]);
        AuthController::verifyCsrf();

        $role = (string) ($_POST['role'] ?? '');
        User::setRole($userId, $role, (int) $admin['id']);

        header('Location: /assessment/admin/users');
        exit;
    }

    /** Pre-provisions a colleague from the same tenant by email with a chosen role, before their first sign-in. */
    public static function addUser(): void
    {
        $admin = AuthController::requireRole([User::ROLE_ADMIN]);
        AuthController::verifyCsrf();

        $email = trim((string) ($_POST['email'] ?? ''));
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $role = (string) ($_POST['role'] ?? User::ROLE_STUDENT);

        if ($email !== '') {
            User::addByEmail($email, $displayName, $role, (int) $admin['id']);
        }

        header('Location: /assessment/admin/users');
        exit;
    }

    /**
     * One-time setup helper: lists shared drives this admin can see, with
     * their Graph drive ids, so ONEDRIVE_DRIVE_ID can be filled in without
     * anyone having to use Graph Explorer or the API directly.
     *
     * Deliberately does NOT call /me/joinedTeams to enumerate every Team -
     * that needs Team.ReadBasic.All, a scope not currently granted (and
     * asking for it means another round of re-consent for everyone).
     * Instead, for each class already imported via Teacher > Classes >
     * Import, it looks up that specific class's own Team drive via
     * /groups/{teamsClassId}/drive - the same pattern the school's other
     * Graph-integrated module uses for uploading files to a class's Team,
     * and it only needs the Files scope already granted.
     */
    public static function oneDriveLookup(): void
    {
        $admin = AuthController::requireRole([User::ROLE_ADMIN]);
        $graph = new GraphApiClient((int) $admin['id']);

        $candidates = [];
        $errors = [];

        try {
            $rootSite = $graph->get('/sites/root/drive');
            $candidates[] = [
                'label' => 'Whole-organisation SharePoint site (' . ($rootSite['name'] ?? 'Documents') . ')',
                'id' => $rootSite['id'] ?? null,
                'note' => 'The default document library for your whole Microsoft 365 tenant. Simplest option if you don\'t already have a dedicated Team.',
            ];
        } catch (Throwable $e) {
            $errors[] = 'Could not read the organisation site drive: ' . $e->getMessage();
        }

        $linkedClasses = ClassRoster::allTeamsLinked();
        if (empty($linkedClasses)) {
            $errors[] = 'No classes have been imported from Teams yet, so no class Team drives can be listed. Import at least one class (Teacher > Classes > Import from Teams) first, or just use the whole-organisation option above.';
        }
        foreach ($linkedClasses as $class) {
            try {
                $drive = $graph->get('/groups/' . $class['teams_class_id'] . '/drive');
                $candidates[] = [
                    'label' => 'Class Team: ' . $class['name'],
                    'id' => $drive['id'] ?? null,
                    'note' => 'Everyone in this Team can read/write files here via their own delegated permissions.',
                ];
            } catch (Throwable $e) {
                $errors[] = 'Could not read the Team drive for "' . $class['name'] . '": ' . $e->getMessage();
            }
        }

        require __DIR__ . '/../views/admin/onedrive_lookup.php';
    }

    public static function auditLog(): void
    {
        AuthController::requireRole([User::ROLE_ADMIN]);
        $entityType = $_GET['entity_type'] ?? '';
        $entityId = (int) ($_GET['entity_id'] ?? 0);
        $entries = ($entityType && $entityId) ? AuditLog::forEntity($entityType, $entityId) : [];
        require __DIR__ . '/../views/admin/audit_log.php';
    }
}
