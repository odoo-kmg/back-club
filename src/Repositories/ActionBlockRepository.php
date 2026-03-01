<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ActionBlockRepository
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }
  private function nowUtc(): string { return gmdate('Y-m-d H:i:s'); }

  /** @return array<int, array<string,mixed>> */
  public function list(array $filters, bool $includeInactive): array
  {
    $now = $this->nowUtc();
    $where = [];
    $vals = [];

    if (!$includeInactive) {
      $where[] = 'active_from <= ? AND (inactive_at IS NULL OR inactive_at > ?)';
      $vals[] = $now; $vals[] = $now;
    }

    if (!empty($filters['scope_type'])) {
      $where[] = 'scope_type = ?';
      $vals[] = $filters['scope_type'];
    }
    if (array_key_exists('scope_draw_id', $filters) && $filters['scope_draw_id'] !== null) {
      $where[] = 'scope_draw_id = ?';
      $vals[] = (int)$filters['scope_draw_id'];
    }
    if (!empty($filters['action_number'])) {
      $where[] = 'action_number = ?';
      $vals[] = (int)$filters['action_number'];
    }

    $sql = "SELECT id, action_number, scope_type, scope_draw_id, reason, source_file_import_id,
                   active_from, inactive_at, created_at, updated_at
            FROM action_block";
    if (count($where) > 0) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY id DESC';

    $st = $this->db->prepare($sql);
    $st->execute($vals);
    return $st->fetchAll();
  }

  public function getById(int $id): ?array
  {
    $sql = "SELECT id, action_number, scope_type, scope_draw_id, reason, source_file_import_id,
                   active_from, inactive_at, created_at, updated_at
            FROM action_block
            WHERE id = ?
            LIMIT 1";
    $st = $this->db->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public function findActiveBlock(int $actionNumber, string $scopeType, ?int $scopeDrawId): ?array
  {
    $now = $this->nowUtc();
    $sql = "SELECT id, action_number, scope_type, scope_draw_id, reason, active_from, inactive_at
            FROM action_block
            WHERE action_number = ?
              AND scope_type = ?
              AND ((? IS NULL AND scope_draw_id IS NULL) OR scope_draw_id = ?)
              AND active_from <= ?
              AND (inactive_at IS NULL OR inactive_at > ?)
            LIMIT 1";
    $st = $this->db->prepare($sql);
    $st->execute([$actionNumber, $scopeType, $scopeDrawId, $scopeDrawId, $now, $now]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public function create(array $data, int $actorUserId): int
  {
    $sql = "INSERT INTO action_block (
              action_number, scope_type, scope_draw_id, reason, source_file_import_id,
              active_from, inactive_at,
              created_at, updated_at, created_by, updated_by
            ) VALUES (
              ?, ?, ?, ?, ?,
              ?, ?,
              NOW(), NOW(), ?, ?
            )";

    $st = $this->db->prepare($sql);
    $st->execute([
      (int)$data['action_number'],
      (string)$data['scope_type'],
      $data['scope_draw_id'],
      (string)$data['reason'],
      $data['source_file_import_id'],
      (string)$data['active_from'],
      $data['inactive_at'],
      $actorUserId,
      $actorUserId,
    ]);

    return (int)$this->db->lastInsertId();
  }

  public function patch(int $id, array $fields, int $actorUserId): void
  {
    $allowed = [
      'reason' => 'reason',
      'active_from' => 'active_from',
      'inactive_at' => 'inactive_at',
    ];

    $sets = [];
    $vals = [];
    foreach ($fields as $k => $v) {
      if (!isset($allowed[$k])) continue;
      $sets[] = $allowed[$k] . ' = ?';
      $vals[] = $v;
    }
    if (count($sets) === 0) return;

    $sql = 'UPDATE action_block SET ' . implode(', ', $sets) . ', updated_at = NOW(), updated_by = ? WHERE id = ?';
    $vals[] = $actorUserId;
    $vals[] = $id;

    $st = $this->db->prepare($sql);
    $st->execute($vals);
  }

  public function unblock(int $id, string $nowUtc, int $actorUserId): void
  {
    $sql = "UPDATE action_block
            SET inactive_at = ?,
                updated_at = NOW(),
                updated_by = ?
            WHERE id = ?";
    $st = $this->db->prepare($sql);
    $st->execute([$nowUtc, $actorUserId, $id]);
  }

  public function logAudit(int $actionBlockId, string $operation, string $reason, string $changedAt, ?int $fileImportId, int $actorUserId): void
  {
    $sql = "INSERT INTO action_block_audit (
              action_block_id, operation, reason, changed_at, file_import_id,
              active_from, inactive_at,
              created_at, updated_at, created_by, updated_by
            ) VALUES (
              ?, ?, ?, ?, ?,
              ?, NULL,
              NOW(), NOW(), ?, ?
            )";
    $st = $this->db->prepare($sql);
    $st->execute([
      $actionBlockId,
      $operation,
      $reason,
      $changedAt,
      $fileImportId,
      $changedAt,
      $actorUserId,
      $actorUserId,
    ]);
  }

  public function isActionBlocked(int $actionNumber, int $drawId): bool
  {
    $now = $this->nowUtc();

    // GLOBAL blocks
    $sqlGlobal = "SELECT id
                  FROM action_block
                  WHERE action_number = ?
                    AND scope_type = 'GLOBAL'
                    AND active_from <= ?
                    AND (inactive_at IS NULL OR inactive_at > ?)
                  LIMIT 1";
    $st = $this->db->prepare($sqlGlobal);
    $st->execute([$actionNumber, $now, $now]);
    if ($st->fetch()) return true;

    // DRAW blocks
    $sqlDraw = "SELECT id
                FROM action_block
                WHERE action_number = ?
                  AND scope_type = 'DRAW'
                  AND scope_draw_id = ?
                  AND active_from <= ?
                  AND (inactive_at IS NULL OR inactive_at > ?)
                LIMIT 1";
    $st2 = $this->db->prepare($sqlDraw);
    $st2->execute([$actionNumber, $drawId, $now, $now]);
    return (bool)$st2->fetch();
  }
}
