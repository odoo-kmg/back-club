<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ParticipationScopeRepository
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }
  private function nowUtc(): string { return date('Y-m-d H:i:s'); }

  private function nowVE(): string { return $this->nowUtc(); }

  /** @return array<int,array<string,mixed>> */
  public function list(?int $eventId, ?int $resourceTypeId, bool $includeInactive = false): array
  {
    $now = $this->nowVE();

    $filters = [];
    $vals = [];

    if ($eventId !== null) {
      $filters[] = "event_id = ?";
      $vals[] = $eventId;
    }
    if ($resourceTypeId !== null) {
      $filters[] = "resource_type_id = ?";
      $vals[] = $resourceTypeId;
    }

    if (!$includeInactive) {
      $filters[] = "active_from <= ? AND (inactive_at IS NULL OR inactive_at > ?)";
      $vals[] = $now;
      $vals[] = $now;
    }

    $where = count($filters) ? ("WHERE " . implode(" AND ", $filters)) : "";
    $sql = "SELECT id, event_id, resource_type_id, code, name, description, active_from, inactive_at, created_at, updated_at
            FROM participation_scope
            {$where}
            ORDER BY id DESC";

    $st = $this->db->prepare($sql);
    $st->execute($vals);
    return $st->fetchAll();
  }

  public function getById(int $id): ?array
  {
    $sql = "SELECT id, event_id, resource_type_id, code, name, description, active_from, inactive_at, created_at, updated_at
            FROM participation_scope
            WHERE id = ?
            LIMIT 1";
    $st = $this->db->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public function create(array $data, int $actorUserId): int
  {
    $sql = "INSERT INTO participation_scope
              (event_id, resource_type_id, code, name, description, active_from, inactive_at, created_at, updated_at, created_by, updated_by)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?)";
    $st = $this->db->prepare($sql);
    $st->execute([
      $data['event_id'],
      $data['resource_type_id'],
      $data['code'],
      $data['name'],
      $data['description'],
      $data['active_from'],
      $data['inactive_at'],
      $actorUserId,
      $actorUserId,
    ]);
    return (int)$this->db->lastInsertId();
  }

  public function patch(int $id, array $fields, int $actorUserId): void
  {
    $allowed = [
      'event_id' => 'event_id',
      'resource_type_id' => 'resource_type_id',
      'code' => 'code',
      'name' => 'name',
      'description' => 'description',
      'active_from' => 'active_from',
      'inactive_at' => 'inactive_at',
    ];

    $sets = [];
    $vals = [];
    foreach ($fields as $k => $v) {
      if (!isset($allowed[$k])) continue;
      $sets[] = $allowed[$k] . " = ?";
      $vals[] = $v;
    }
    if (count($sets) === 0) return;

    $sql = "UPDATE participation_scope SET " . implode(', ', $sets) . ", updated_at = NOW(), updated_by = ? WHERE id = ?";
    $vals[] = $actorUserId;
    $vals[] = $id;
    $st = $this->db->prepare($sql);
    $st->execute($vals);
  }
}
