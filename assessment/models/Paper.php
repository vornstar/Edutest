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
}
