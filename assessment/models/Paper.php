<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class Paper
{
    public static function create(array $data): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO papers (title, subject, type, created_by, pdf_drive_item_id, mark_scheme_drive_item_id, self_marking_enabled, duration_minutes, status)
             VALUES (:title, :subject, :type, :created_by, :pdf_drive_item_id, :mark_scheme_drive_item_id, :self_marking_enabled, :duration_minutes, :status)'
        );
        $stmt->execute([
            'title' => $data['title'],
            'subject' => $data['subject'] ?? null,
            'type' => $data['type'] ?? 'digital',
            'created_by' => $data['created_by'],
            'pdf_drive_item_id' => $data['pdf_drive_item_id'] ?? null,
            'mark_scheme_drive_item_id' => $data['mark_scheme_drive_item_id'] ?? null,
            'self_marking_enabled' => !empty($data['self_marking_enabled']) ? 1 : 0,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'status' => $data['status'] ?? 'draft',
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM papers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function byCreator(int $userId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM papers WHERE created_by = :created_by ORDER BY created_at DESC');
        $stmt->execute(['created_by' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Papers a teacher-portal user can see in their own list: everything
     * they created, plus - for a Subject Leader - everything under their
     * managed subject too (department-wide oversight, SRS 3.2), plus -
     * for an Admin - every paper on the platform.
     */
    public static function visibleTo(array $user): array
    {
        $pdo = Database::connection();

        if ($user['role'] === 'admin') {
            return $pdo->query('SELECT * FROM papers ORDER BY created_at DESC')->fetchAll();
        }

        if ($user['role'] === 'subject_leader' && !empty($user['managed_subject'])) {
            $stmt = $pdo->prepare('SELECT * FROM papers WHERE created_by = :created_by OR subject = :subject ORDER BY created_at DESC');
            $stmt->execute(['created_by' => $user['id'], 'subject' => $user['managed_subject']]);
            return $stmt->fetchAll();
        }

        return self::byCreator((int) $user['id']);
    }

    public static function allPublished(): array
    {
        $stmt = Database::connection()->query("SELECT * FROM papers WHERE status = 'published' ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }

    public static function setSelfMarking(int $paperId, bool $enabled): void
    {
        $stmt = Database::connection()->prepare('UPDATE papers SET self_marking_enabled = :enabled WHERE id = :id');
        $stmt->execute(['enabled' => $enabled ? 1 : 0, 'id' => $paperId]);
    }

    public static function publish(int $paperId): void
    {
        $stmt = Database::connection()->prepare("UPDATE papers SET status = 'published' WHERE id = :id");
        $stmt->execute(['id' => $paperId]);
    }

    public static function attachPdf(int $paperId, string $pdfDriveItemId, ?string $markSchemeDriveItemId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE papers SET pdf_drive_item_id = :pdf, mark_scheme_drive_item_id = :ms WHERE id = :id'
        );
        $stmt->execute(['pdf' => $pdfDriveItemId, 'ms' => $markSchemeDriveItemId, 'id' => $paperId]);
    }

    /** Replaces just one of the two PDF files on an existing paper, leaving the other untouched. */
    public static function replacePdfFile(int $paperId, string $field, string $driveItemId): void
    {
        if (!in_array($field, ['pdf_drive_item_id', 'mark_scheme_drive_item_id'], true)) {
            throw new InvalidArgumentException('Invalid PDF field.');
        }
        $stmt = Database::connection()->prepare("UPDATE papers SET {$field} = :item_id WHERE id = :id");
        $stmt->execute(['item_id' => $driveItemId, 'id' => $paperId]);
    }

    /** Whether any student has started/submitted work against this paper - used to block accidental deletion of real work. */
    public static function hasSubmissions(int $paperId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM submissions s
             INNER JOIN test_assignments a ON a.id = s.assignment_id
             WHERE a.paper_id = :paper_id LIMIT 1'
        );
        $stmt->execute(['paper_id' => $paperId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Deletes a paper and everything under it (questions, assignments, ...) via ON DELETE CASCADE - refuse if any student has submissions, see hasSubmissions(). */
    public static function delete(int $paperId): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM papers WHERE id = :id');
        $stmt->execute(['id' => $paperId]);
    }
}
