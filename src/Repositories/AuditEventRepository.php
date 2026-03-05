<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Minimal audit repository.
 *
 * Nota operativa:
 * - Este proyecto corre en hosting compartido (sin colas/cron).
 * - La auditoría NO debe romper el flujo crítico si la tabla aún no existe
 *   o si el esquema varía entre ambientes.
 */
final class AuditEventRepository
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }

  /**
   * @param array<string,mixed>|null $payload
   */
  public function insert(
    ?int $actorUserId,
    string $actionCode,
    string $entityType,
    int $entityId,
    ?array $payload,
    ?int $createdBy
  ): void {
    $nowUtc = gmdate('Y-m-d H:i:s');
    $payloadJson = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;
    if ($payload && $payloadJson === false) $payloadJson = '{"error":"payload_encode"}';

    // Best-effort insert. If schema differs, do not break the request.
    try {
      $sql = "INSERT INTO audit_event (
                actor_user_id,
                action_code,
                entity_type,
                entity_id,
                payload_json,
                active_from,
                inactive_at,
                created_at,
                updated_at,
                created_by,
                updated_by
              ) VALUES (
                ?, ?, ?, ?, ?,
                ?, NULL,
                NOW(), NOW(), ?, ?
              )";
      $st = $this->db->prepare($sql);
      $st->execute([
        $actorUserId,
        $actionCode,
        $entityType,
        $entityId,
        $payloadJson,
        $nowUtc,
        $createdBy,
        $createdBy,
      ]);
    } catch (\PDOException $e) {
      // swallow
    }
  }
}
