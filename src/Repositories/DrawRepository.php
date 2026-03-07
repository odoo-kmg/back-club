<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class DrawRepository
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }

  private function nowUtc(): string { return date('Y-m-d H:i:s'); }

  private function nowVE(): string { return $this->nowUtc(); }

  /** @return array<int, array<string,mixed>> */
  public function list(array $filters, bool $includeInactive = false): array
  {
    $now = $this->nowVE();

    $where = [];
    $vals = [];

    if (!$includeInactive) {
      $where[] = 'active_from <= ? AND (inactive_at IS NULL OR inactive_at > ?)';
      $vals[] = $now;
      $vals[] = $now;
    }

    if (isset($filters['event_id'])) {
      $where[] = 'event_id = ?';
      $vals[] = (int)$filters['event_id'];
    }

    if (isset($filters['status'])) {
      $where[] = 'status = ?';
      $vals[] = (string)$filters['status'];
    }

    if (!empty($filters['for_registration'])) {
      // Only draws explicitly open for registration and within configured window.
      $where[] = "status = 'REG_OPEN'";
      $where[] = 'reg_open_at <= ? AND reg_close_at > ?';
      $vals[] = $now;
      $vals[] = $now;
    }

    $sql = "SELECT id, event_id, resource_type_id, participation_scope_id, name, resource_context,
                   reg_open_at, reg_close_at, winners_count, pick_interval_seconds,
                   use_start_date, use_end_date, status,
                   active_from, inactive_at, created_at, updated_at
            FROM `draw`";
    if (count($where) > 0) {
      $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY id DESC';

    $st = $this->db->prepare($sql);
    $st->execute($vals);
    return $st->fetchAll();
  }

  public function getById(int $id): ?array
  {
    $sql = "SELECT id, event_id, resource_type_id, participation_scope_id, name, resource_context,
                   reg_open_at, reg_close_at, winners_count, pick_interval_seconds,
                   use_start_date, use_end_date, status,
                   active_from, inactive_at, created_at, updated_at
            FROM `draw`
            WHERE id = ?
            LIMIT 1";
    $st = $this->db->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public function create(array $data, int $actorUserId): int
  {
    $sql = "INSERT INTO `draw` (
              event_id, resource_type_id, participation_scope_id,
              name, resource_context,
              reg_open_at, reg_close_at,
              winners_count, pick_interval_seconds,
              use_start_date, use_end_date,
              status,
              active_from, inactive_at,
              created_at, updated_at, created_by, updated_by
            ) VALUES (
              ?, ?, ?,
              ?, ?,
              ?, ?,
              ?, ?,
              ?, ?,
              ?,
              ?, ?,
              NOW(), NOW(), ?, ?
            )";

    $st = $this->db->prepare($sql);
    $st->execute([
      (int)$data['event_id'],
      (int)$data['resource_type_id'],
      (int)$data['participation_scope_id'],
      (string)$data['name'],
      $data['resource_context'],
      (string)$data['reg_open_at'],
      (string)$data['reg_close_at'],
      (int)$data['winners_count'],
      (int)$data['pick_interval_seconds'],
      $data['use_start_date'],
      $data['use_end_date'],
      (string)$data['status'],
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
      'event_id' => 'event_id',
      'resource_type_id' => 'resource_type_id',
      'participation_scope_id' => 'participation_scope_id',
      'name' => 'name',
      'resource_context' => 'resource_context',
      'reg_open_at' => 'reg_open_at',
      'reg_close_at' => 'reg_close_at',
      'winners_count' => 'winners_count',
      'pick_interval_seconds' => 'pick_interval_seconds',
      'use_start_date' => 'use_start_date',
      'use_end_date' => 'use_end_date',
      'status' => 'status',
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

    $sql = 'UPDATE `draw` SET ' . implode(', ', $sets) . ', updated_at = NOW(), updated_by = ? WHERE id = ?';
    $vals[] = $actorUserId;
    $vals[] = $id;

    $st = $this->db->prepare($sql);
    $st->execute($vals);
  }

  public function setStatus(int $id, string $status, int $actorUserId): void
  {
    $sql = "UPDATE `draw` SET status = ?, updated_at = NOW(), updated_by = ? WHERE id = ?";
    $st = $this->db->prepare($sql);
    $st->execute([$status, $actorUserId, $id]);
  }
}
