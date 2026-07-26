<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class AuditLog
{
    public static function record(string $entityType, int $entityId, ?int $actorId, string $action, ?array $before = null, ?array $after = null): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO audit_log (entity_type, entity_id, actor_id, action, before_json, after_json)
             VALUES (:entity_type, :entity_id, :actor_id, :action, :before_json, :after_json)'
        );
        $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'actor_id' => $actorId,
            'action' => $action,
            'before_json' => $before === null ? null : json_encode($before),
            'after_json' => $after === null ? null : json_encode($after),
        ]);
    }

    public static function forEntity(string $entityType, int $entityId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM audit_log WHERE entity_type = :entity_type AND entity_id = :entity_id ORDER BY created_at ASC'
        );
        $stmt->execute(['entity_type' => $entityType, 'entity_id' => $entityId]);
        return $stmt->fetchAll();
    }
}
