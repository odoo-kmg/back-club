<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AuditEventRepository
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }

  public function insert(
    int $userId,
    string $eventType,
    string $entityType,
    ?int $entityId,
    array $payload,
    int $actorUserId
  ): ?int {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) $json = '{}';

    try {
      $st = $this->db->prepare("INSERT INTO audit_event (
        user_id,
        event_type,
        entity_type,
        entity_id,
        payload_json,
        created_at,
        updated_at,
        created_by,
        updated_by
      ) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?, ?)");
      $st->execute([
        $userId,
        $eventType,
        $entityType,
        $entityId,
        $json,
        $actorUserId,
        $actorUserId,
      ]);
      return (int)$this->db->lastInsertId();
    } catch (\Throwable $e) {
      // Auditoría best-effort: no romper flujo operativo si la tabla difiere o no existe.
      return null;
    }
  }
}
