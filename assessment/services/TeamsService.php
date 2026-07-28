<?php
declare(strict_types=1);

require_once __DIR__ . '/GraphApiClient.php';
require_once __DIR__ . '/../models/ClassRoster.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/TestAssignment.php';
require_once __DIR__ . '/../models/Submission.php';
require_once __DIR__ . '/../models/Mark.php';

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
        return $this->graph->getAll('/education/me/classes', [
            '$select' => 'id,displayName,description,externalName,term',
        ]);
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

        $members = $this->graph->getAll("/education/classes/{$teamsClassId}/members", [
            '$select' => 'id,displayName,mail,userPrincipalName,primaryRole',
        ]);
        foreach ($members as $member) {
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
            // Graph's own "id" for a class member is their Azure AD object id -
            // captured here (not just at login) so a grade can be written
            // back to their Teams submission later without a separate
            // lookup (see pushGrade()).
            $user = User::provisionFromRoster($email, (string) ($member['displayName'] ?? $email), (string) ($member['id'] ?? '') ?: null);
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
    public function pushAssignment(int $localAssignmentId, string $teamsClassId, string $title, ?string $dueAt, string $deepLinkUrl, ?float $maxMarks = null): string
    {
        $body = [
            'displayName' => $title,
            'instructions' => [
                'content' => "Complete this assessment: {$deepLinkUrl}",
                'contentType' => 'html',
            ],
            'assignTo' => ['@odata.type' => '#microsoft.graph.educationAssignmentClassRecipient'],
        ];
        if ($dueAt) {
            $body['dueDateTime'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($dueAt));
        }
        if ($maxMarks !== null) {
            $body['grading'] = [
                '@odata.type' => 'microsoft.graph.educationAssignmentPointsGradeType',
                'maxPoints' => $maxMarks,
            ];
        }

        // Graph only allows creating an assignment as a draft - status is
        // rejected (HTTP 400) if set to anything else on creation. Making
        // it visible to students takes a separate call to the dedicated
        // /publish action afterward.
        $result = $this->graph->post("/education/classes/{$teamsClassId}/assignments", $body);
        $teamsAssignmentId = (string) $result['id'];
        $this->graph->post("/education/classes/{$teamsClassId}/assignments/{$teamsAssignmentId}/publish", []);

        TestAssignment::setTeamsAssignmentId($localAssignmentId, $teamsAssignmentId);
        return $teamsAssignmentId;
    }

    /** Removes a pushed assignment from Teams entirely - used when a teacher cancels a test (see TestController::cancelTest). Graph, not this app, owns any of that assignment's submission data on the Teams side, so this doesn't touch anything local. */
    public function deleteAssignment(string $teamsClassId, string $teamsAssignmentId): void
    {
        $this->graph->delete("/education/classes/{$teamsClassId}/assignments/{$teamsAssignmentId}");
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

    /**
     * Writes a mark back to the student's Teams submission for a pushed
     * assignment, and releases it so they (and the Teams gradebook) can see
     * it. Matches the Teams submission by the student's Azure AD object id
     * (captured at roster sync time, see syncClassRoster()) against each
     * submission's recipient.userId - Graph has no direct "get submission
     * for this user" lookup, so this fetches the (class-sized, so small)
     * full submissions list and matches locally.
     */
    public function pushGrade(string $teamsClassId, string $teamsAssignmentId, string $studentAadObjectId, float $score): void
    {
        $submissions = $this->graph->getAll("/education/classes/{$teamsClassId}/assignments/{$teamsAssignmentId}/submissions");

        $match = null;
        foreach ($submissions as $s) {
            if (($s['recipient']['userId'] ?? null) === $studentAadObjectId) {
                $match = $s;
                break;
            }
        }
        if (!$match) {
            throw new RuntimeException('No matching Teams submission found for this student on this assignment.');
        }

        $this->graph->patch("/education/classes/{$teamsClassId}/assignments/{$teamsAssignmentId}/submissions/{$match['id']}", [
            'grade' => [
                '@odata.type' => 'microsoft.graph.educationAssignmentPointsGrade',
                'points' => $score,
            ],
        ]);
        $this->graph->post("/education/classes/{$teamsClassId}/assignments/{$teamsAssignmentId}/submissions/{$match['id']}/return", []);
    }

    /**
     * If this submission's assignment was pushed to Teams (see
     * TestController::assign), write $markType's total score back to the
     * matching Teams submission and release it so the student/gradebook
     * sees it there too - called after saving primary marks
     * (MarkingController::saveMark) AND after completing moderation
     * (ModerationController::submitReview), so a moderation pass that
     * changes the mark keeps Teams in sync as well, not just the initial
     * primary mark. Best-effort: the local mark is already saved
     * regardless, so a Graph failure here (e.g. the roster hasn't been
     * re-synced since this student joined, so their aad_object_id isn't
     * known yet) must not block marking/moderation.
     */
    public static function pushGradeForSubmission(int $submissionId, int $actingUserId, string $markType = 'primary'): void
    {
        $submission = Submission::find($submissionId);
        $assignment = $submission ? TestAssignment::find((int) $submission['assignment_id']) : null;
        if (!$assignment || empty($assignment['teams_assignment_id']) || empty($assignment['class_id'])) {
            return;
        }

        $class = ClassRoster::find((int) $assignment['class_id']);
        $student = User::find((int) $submission['student_id']);
        if (!$class || empty($class['teams_class_id']) || empty($student['aad_object_id'])) {
            return;
        }

        $score = Mark::totalScore($submissionId, $markType);

        try {
            $teams = new self($actingUserId);
            $teams->pushGrade((string) $class['teams_class_id'], (string) $assignment['teams_assignment_id'], (string) $student['aad_object_id'], $score);
        } catch (Throwable $e) {
            error_log('Teams grade push failed for submission ' . $submissionId . ' (' . $markType . '): ' . $e->getMessage());
        }
    }
}
