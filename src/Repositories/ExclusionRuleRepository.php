<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ExclusionRuleRepository
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }

  private function nowUtc(): string { return gmdate('Y-m-d H:i:s'); }

  /** @return array<int, array<string,mixed>> */
  public function listBySourceDraw(int $drawId, bool $includeInactive = false): array
  {
    $now = $this->nowUtc();

    if ($includeInactive) {
      $sql = "SELECT id, rule_type, source_draw_id, target_draw_id, active_from, inactive_at, created_at, updated_at
              FROM draw_exclusion_rule
              WHERE source_draw_id = ?
              ORDER BY id DESC";
      $st = $this->db->prepare($sql);
      $st->execute([$drawId]);
      return $st->fetchAll();
    }

    $sql = "SELECT id, rule_type, source_draw_id, target_draw_id, active_from, inactive_at, created_at, updated_at
            FROM draw_exclusion_rule
            WHERE source_draw_id = ?
              AND active_from <= ?
              AND (inactive_at IS NULL OR inactive_at > ?)
            ORDER BY id DESC";
    $st = $this->db->prepare($sql);
    $st->execute([$drawId, $now, $now]);
    return $st->fetchAll();
  }

  public function getById(int $id): ?array
  {
    $sql = "SELECT id, rule_type, source_draw_id, target_draw_id, active_from, inactive_at, created_at, updated_at
            FROM draw_exclusion_rule
            WHERE id = ?
            LIMIT 1";
    $st = $this->db->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public function create(int $sourceDrawId, array $data, int $actorUserId): int
  {
    $sql = "INSERT INTO draw_exclusion_rule
              (rule_type, source_draw_id, target_draw_id, active_from, inactive_at, created_at, updated_at, created_by, updated_by)
            VALUES
              (?, ?, ?, ?, ?, NOW(), NOW(), ?, ?)";
    $st = $this->db->prepare($sql);
    $st->execute([
      (string)$data['rule_type'],
      $sourceDrawId,
      (int)$data['target_draw_id'],
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

    $sql = 'UPDATE draw_exclusion_rule SET ' . implode(', ', $sets) . ', updated_at = NOW(), updated_by = ? WHERE id = ?';
    $vals[] = $actorUserId;
    $vals[] = $id;

    $st = $this->db->prepare($sql);
    $st->execute($vals);
  }
}
