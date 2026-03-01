<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class DrawExecutionRepository
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }
  private function nowUtc(): string { return gmdate('Y-m-d H:i:s'); }

  public function getById(int $id): ?array
  {
    $st = $this->db->prepare("SELECT *
                              FROM draw_execution
                              WHERE id = ?
                              LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public function create(int $drawId, string $mode, ?int $baseExecutionId, int $executedByUserId, string $configSnapshotJson): int
  {
    $now = $this->nowUtc();

    $sql = "INSERT INTO draw_execution (
              draw_id,
              status, mode, base_execution_id,
              started_at, ended_at,
              executed_by_user_id,
              config_snapshot, rng_snapshot,
              active_from, inactive_at,
              created_at, updated_at, created_by, updated_by
            ) VALUES (
              ?,
              'STARTED', ?, ?,
              ?, NULL,
              ?,
              ?, NULL,
              ?, NULL,
              NOW(), NOW(), ?, ?
            )";

    $st = $this->db->prepare($sql);
    $st->execute([
      $drawId,
      $mode,
      $baseExecutionId,
      $now,
      $executedByUserId,
      $configSnapshotJson,
      $now,
      $executedByUserId,
      $executedByUserId,
    ]);

    return (int)$this->db->lastInsertId();
  }

  public function markFinished(int $executionId, int $actorUserId): void
  {
    $now = $this->nowUtc();
    $sql = "UPDATE draw_execution
            SET status = 'FINISHED',
                ended_at = ?,
                updated_at = NOW(),
                updated_by = ?
            WHERE id = ?";
    $st = $this->db->prepare($sql);
    $st->execute([$now, $actorUserId, $executionId]);
  }

  public function markStarted(int $executionId, int $actorUserId): void
  {
    $sql = "UPDATE draw_execution
            SET status = 'STARTED',
                ended_at = NULL,
                updated_at = NOW(),
                updated_by = ?
            WHERE id = ?";
    $st = $this->db->prepare($sql);
    $st->execute([$actorUserId, $executionId]);
  }

  public function updateDrawStatus(int $drawId, string $status, int $actorUserId): void
  {
    $sql = "UPDATE draw
            SET status = ?,
                updated_at = NOW(),
                updated_by = ?
            WHERE id = ?";
    $st = $this->db->prepare($sql);
    $st->execute([$status, $actorUserId, $drawId]);
  }
}
