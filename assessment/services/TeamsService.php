<?php
declare(strict_types=1);

require_once __DIR__ . '/GraphApiClient.php';
require_once __DIR__ . '/../models/ClassRoster.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/TestAssignment.php';

/**
 * Microsoft Teams / Education Graph API integration: class roster sync,
 * assignment push, and gradebook status sync (SRS section 4).
 */
final class TeamsService
{
    private GraphApiClient $graph;
    private int $actingUserId;

    public function __construct(int $actingUserId)
    {
        $this->actingUserId = $actingUserId;
        $this->graph = new GraphApiClient($actingUserId);
    }

    /** Lists the Teams classes (education classes / M365 groups) the signed-in teacher owns. */
    public function listMyClasses(): array
    {
        $result = $this->graph->get('/education/me/classes');
        return $result['value'] ?? [];
    }

    /**
     * Imports/syncs a Teams class roster into the local database: creates
     * (or reuses) the class row and enrolls every member found via
     * /education/classes/{id}/members, mapping them to local users by email.
     */
    public function syncClassRoster(string $teamsClassId, int $teacherId): int
    {
        $classInfo = $this->graph->get("/education/classes/{$teamsClassId}");

        $existing = ClassRoster::findByTeamsId($teamsClassId);
        $classId = $existing['id'] ?? ClassRoster::create(
            (string) ($classInfo['displayName'] ?? 'Untitled class'),
            (string) ($classInfo['description'] ?? ''),
            $teacherId,
            $teamsClassId
        );

        $members = $this->graph->get("/education/classes/{$teamsClassId}/members");
        foreach ($members['value'] ?? [] as $member) {
            $email = strtolower((string) ($member['mail'] ?? $member['userPrincipalName'] ?? ''));
            if ($email === '') {
                continue;
            }
            // Every roster member is enrolled as a class-level 'student' by
            // default - the platform's global role (student/teacher/...)
            // is never touched here, only set explicitly by an Admin (see
            // User::addByEmail / setRole). A member Graph reports as the
            // class's own teacher is enrolled as class-level 'teacher' so
            // they show up correctly on the class roster, but that still
            // says nothing about their platform-wide role.
            $user = User::provisionFromRoster($email, (string) ($member['displayName'] ?? $email));
            $roleInClass = ($member['primaryRole'] ?? 'student') === 'teacher' ? 'teacher' : 'student';
            ClassRoster::enroll($classId, (int) $user['id'], $roleInClass);
        }

        ClassRoster::markSynced($classId);
        return $classId;
    }

    /**
     * Pushes a Teams Assignment for the given local test assignment via
     * /education/classes/{id}/assignments, and stores the returned
     * assignment id + deep link back on our record.
     */
    public function pushAssignment(int $localAssignmentId, string $teamsClassId, string $title, ?string $dueAt, string $deepLinkUrl): string
    {
        $body = [
            'displayName' => $title,
            'instructions' => [
                'content' => "Complete this assessment: {$deepLinkUrl}",
                'contentType' => 'html',
            ],
            'assignTo' => ['@odata.type' => '#microsoft.graph.educationAssignmentClassRecipient'],
            'status' => 'published',
        ];
        if ($dueAt) {
            $body['dueDateTime'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($dueAt));
        }

        $result = $this->graph->post("/education/classes/{$teamsClassId}/assignments", $body);
        $teamsAssignmentId = (string) $result['id'];

        TestAssignment::setTeamsAssignmentId($localAssignmentId, $teamsAssignmentId);
        return $teamsAssignmentId;
    }

    /** Optional status sync back to the Teams gradebook (Assigned / Submitted / Graded). */
    public function syncStatus(string $teamsClassId, string $teamsAssignmentId, string $status): void
    {
        $map = ['assigned' => 'assigned', 'submitted' => 'submitted', 'graded' => 'graded'];
        $graphStatus = $map[$status] ?? 'assigned';
        $this->graph->patch("/education/classes/{$teamsClassId}/assignments/{$teamsAssignmentId}", [
            'status' => $graphStatus === 'assigned' ? 'published' : $graphStatus,
        ]);
    }
}
