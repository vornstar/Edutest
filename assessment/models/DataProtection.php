<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Crypto.php';
require_once __DIR__ . '/User.php';
require_once __DIR__ . '/Submission.php';
require_once __DIR__ . '/AuditLog.php';

/**
 * Admin-only tooling to support UK GDPR / Data Protection Act 2018
 * obligations: a subject access export (Article 15) and ways to reduce
 * how much personal data is kept over time (storage limitation, Article
 * 5(1)(e), and erasure, Article 17). This is a technical tool, not legal
 * advice - a school remains responsible for its own retention schedule,
 * lawful basis, and how/when it actually exercises these actions; nothing
 * here decides that for them. See AdminController::dataProtection.
 */
final class DataProtection
{
    /**
     * Every piece of personal data this app holds about one user, decrypted,
     * for a subject access request. Covers their profile, class membership,
     * every submission of their own (answers, self-marks, marks received,
     * in-PDF annotations), any marks they've given as a marker, and audit
     * log entries where they're the actor. OneDrive-held files (a scanned
     * script, if any) are referenced by presence only - not fetched here,
     * since exporting binary content isn't necessary for a text-based
     * export and would need a live Graph session.
     */
    public static function exportUser(int $userId): array
    {
        $pdo = Database::connection();
        $user = User::find($userId);
        if (!$user) {
            throw new InvalidArgumentException('User not found.');
        }

        $enrollStmt = $pdo->prepare(
            'SELECT c.name AS class_name, c.subject, ce.role_in_class FROM class_enrollments ce
             INNER JOIN classes c ON c.id = ce.class_id WHERE ce.user_id = :user_id ORDER BY c.name'
        );
        $enrollStmt->execute(['user_id' => $userId]);
        $enrollments = $enrollStmt->fetchAll();

        $submissions = [];
        foreach (Submission::forStudent($userId) as $s) {
            $answers = array_map(
                static fn(array $a): array => ['question_id' => (int) $a['question_id'], 'answer_text' => $a['answer_text']],
                array_values(Submission::answers((int) $s['id']))
            );
            $selfMarks = array_map(
                static fn(array $m): array => ['question_id' => (int) $m['question_id'], 'student_mark' => (float) $m['student_mark'], 'reflection_comment' => $m['reflection_comment']],
                array_values(Submission::selfMarks((int) $s['id']))
            );

            $marksStmt = $pdo->prepare('SELECT question_id, mark_type, score, comment_cipher, created_at FROM marks WHERE submission_id = :submission_id ORDER BY created_at ASC');
            $marksStmt->execute(['submission_id' => (int) $s['id']]);
            $marksReceived = array_map(static function (array $m): array {
                return [
                    'question_id' => $m['question_id'] !== null ? (int) $m['question_id'] : null,
                    'mark_type' => $m['mark_type'],
                    'score' => (float) $m['score'],
                    'comment' => Crypto::decrypt($m['comment_cipher']),
                    'created_at' => $m['created_at'],
                ];
            }, $marksStmt->fetchAll());

            $annotationsStmt = $pdo->prepare('SELECT page_number, version, data_cipher, created_at FROM annotations WHERE submission_id = :submission_id AND marker_id = :marker_id ORDER BY page_number, version');
            $annotationsStmt->execute(['submission_id' => (int) $s['id'], 'marker_id' => $userId]);
            $ownAnnotations = array_map(static function (array $a): array {
                return [
                    'page_number' => (int) $a['page_number'],
                    'version' => (int) $a['version'],
                    'fabric_json' => json_decode(Crypto::decrypt($a['data_cipher']) ?? 'null', true),
                    'created_at' => $a['created_at'],
                ];
            }, $annotationsStmt->fetchAll());

            $submissions[] = [
                'submission_id' => (int) $s['id'],
                'paper_title' => $s['paper_title'],
                'status' => $s['status'],
                'started_at' => $s['started_at'],
                'submitted_at' => $s['submitted_at'],
                'has_scanned_script' => !empty($s['scan_drive_item_id']),
                'answers' => $answers,
                'self_marks' => $selfMarks,
                'marks_received' => $marksReceived,
                'own_annotations' => $ownAnnotations,
            ];
        }

        $marksGivenStmt = $pdo->prepare('SELECT submission_id, question_id, mark_type, score, comment_cipher, created_at FROM marks WHERE marker_id = :marker_id ORDER BY created_at ASC');
        $marksGivenStmt->execute(['marker_id' => $userId]);
        $marksGiven = array_map(static function (array $m): array {
            return [
                'submission_id' => (int) $m['submission_id'],
                'question_id' => $m['question_id'] !== null ? (int) $m['question_id'] : null,
                'mark_type' => $m['mark_type'],
                'score' => (float) $m['score'],
                'comment' => Crypto::decrypt($m['comment_cipher']),
                'created_at' => $m['created_at'],
            ];
        }, $marksGivenStmt->fetchAll());

        $auditStmt = $pdo->prepare('SELECT entity_type, entity_id, action, before_json, after_json, created_at FROM audit_log WHERE actor_id = :actor_id ORDER BY created_at ASC');
        $auditStmt->execute(['actor_id' => $userId]);
        $auditEntries = $auditStmt->fetchAll();

        return [
            'exported_at' => date('c'),
            'profile' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'display_name' => $user['display_name'],
                'role' => $user['role'],
                'managed_subject' => $user['managed_subject'],
                'account_created_at' => $user['created_at'],
            ],
            'class_enrollments' => $enrollments,
            'submissions_as_student' => $submissions,
            'marks_given_as_marker' => $marksGiven,
            'audit_log_as_actor' => $auditEntries,
        ];
    }

    /**
     * Scrubs a user's identifying fields (email, display name, Azure AD
     * id) while leaving the row and every relationship in place - papers
     * they created, submissions, marks they gave or received all stay
     * exactly where they are, structurally. Foreign keys throughout this
     * schema deliberately don't cascade-delete from users (see schema.sql)
     * precisely because removing the row itself would either fail outright
     * or silently destroy other people's records (a class, a paper, a
     * moderation decision) that have nothing to do with the erasure
     * request - anonymizing is the actual mechanism that satisfies "erase
     * my personal data" without doing that. Their own submission content
     * (answers, annotations, self-marks) is untouched here - see
     * deleteSubmissionsOlderThan() for actually removing that, separately,
     * on age/retention grounds rather than as part of an identity erasure.
     */
    public static function anonymizeUser(int $userId, int $actingAdminId): void
    {
        $user = User::find($userId);
        if (!$user) {
            throw new InvalidArgumentException('User not found.');
        }

        $placeholderEmail = 'deleted-user-' . $userId . '@anonymized.invalid';
        // A random suffix (not just the plain placeholder) keeps email_hash unique per
        // anonymized user - it's a UNIQUE column - without making it reversible to anyone's
        // real address; the hash is one-way (HMAC) regardless.
        $uniqueSalt = bin2hex(random_bytes(8));

        $stmt = Database::connection()->prepare(
            'UPDATE users SET
                email_cipher = :email_cipher,
                email_hash = :email_hash,
                display_name_cipher = :name_cipher,
                aad_object_id = NULL,
                site_user_id = NULL
             WHERE id = :id'
        );
        $stmt->execute([
            'email_cipher' => Crypto::encrypt($placeholderEmail),
            'email_hash' => Crypto::searchHash($placeholderEmail . '#' . $uniqueSalt),
            'name_cipher' => Crypto::encrypt('Deleted user #' . $userId),
            'id' => $userId,
        ]);

        // Deliberately no before/after payload here (their old identity is exactly what's
        // being erased) - only enough to show an erasure happened, when, by whom, for what
        // role of account, without perpetuating the very data this action removes.
        AuditLog::record('user', $userId, $actingAdminId, 'gdpr_anonymized', null, ['role_at_time' => $user['role']]);
    }

    /**
     * Every submission started before $cutoffDate (Y-m-d), for previewing
     * what a retention-based deletion would remove before committing to
     * it - see deleteSubmissionsOlderThan(). Deliberately lightweight (no
     * decryption): just enough to judge scale and date range.
     */
    public static function submissionsOlderThan(string $cutoffDate): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS total, MIN(started_at) AS oldest, MAX(started_at) AS newest
             FROM submissions WHERE started_at < :cutoff'
        );
        $stmt->execute(['cutoff' => $cutoffDate]);
        $row = $stmt->fetch();
        return [
            'total' => (int) ($row['total'] ?? 0),
            'oldest' => $row['oldest'] ?? null,
            'newest' => $row['newest'] ?? null,
        ];
    }

    /**
     * Hard-deletes every submission started before $cutoffDate, and
     * everything under it - answers, self_marks, marks, annotations,
     * moderation_assignments all cascade via their own ON DELETE CASCADE
     * FK to submissions (see schema.sql), no separate cleanup needed for
     * those. A scanned script (if any) lives on OneDrive, not in this
     * database, so it's removed first, best-effort, one submission at a
     * time - a Graph failure there is logged and skipped rather than
     * aborting the whole run, matching this app's usual best-effort
     * pattern for OneDrive cleanup (see TestController::cancelTest).
     *
     * Irreversible. Unlike every other "delete" in this app (which is
     * carefully a soft delete - see TestAssignment::cancel), this one
     * genuinely removes rows, because that is the entire point of a
     * storage-limitation/retention tool - see AdminController::
     * deleteOldData for the confirmation gate this sits behind.
     *
     * @return int number of submissions deleted
     */
    public static function deleteSubmissionsOlderThan(string $cutoffDate, int $actingUserId, int $actingAdminId): int
    {
        require_once __DIR__ . '/../services/OneDriveService.php';

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, scan_drive_item_id FROM submissions WHERE started_at < :cutoff');
        $stmt->execute(['cutoff' => $cutoffDate]);
        $rows = $stmt->fetchAll();

        if (!$rows) {
            return 0;
        }

        $drive = null;
        foreach ($rows as $row) {
            if (empty($row['scan_drive_item_id'])) {
                continue;
            }
            try {
                $drive = $drive ?? new OneDriveService($actingUserId);
                $drive->deleteItem((string) $row['scan_drive_item_id']);
            } catch (Throwable $e) {
                error_log('GDPR retention cleanup: failed to delete OneDrive scan for submission ' . $row['id'] . ': ' . $e->getMessage());
            }
        }

        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM submissions WHERE id IN ({$placeholders})")->execute($ids);

        AuditLog::record('data_retention', 0, $actingAdminId, 'gdpr_bulk_delete_submissions', null, [
            'cutoff_date' => $cutoffDate,
            'submissions_deleted' => count($ids),
        ]);

        return count($ids);
    }
}
