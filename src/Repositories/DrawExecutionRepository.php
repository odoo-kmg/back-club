<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class DrawExecutionRepository
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }
  private function nowUtc(): string { return date('Y-m-d H:i:s'); }

  private function nowVE(): string { return $this->nowUtc(); }

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

  /**
   * Returns the currently STARTED execution id for a draw (if any).
   * We treat STARTED as the active lock.
   */
  public function findStartedExecutionIdByDraw(int $drawId): ?int
  {
    $st = $this->db->prepare("SELECT id
                              FROM draw_execution
                              WHERE draw_id = ?
                                AND status = 'STARTED'
                                AND inactive_at IS NULL
                              ORDER BY id DESC
                              LIMIT 1");
    $st->execute([$drawId]);
    $row = $st->fetch();
    if (!$row) return null;
    return (int)$row['id'];
  }

  /**
   * Safety: finalize any started executions for draw to avoid multiple active locks.
   */
  public function finishAllStartedByDraw(int $drawId, int $actorUserId): int
  {
    $now = $this->nowVE();
    $st = $this->db->prepare("UPDATE draw_execution
                              SET status = 'FINISHED',
                                  ended_at = ?,
                                  updated_at = NOW(),
                                  updated_by = ?
                              WHERE draw_id = ?
                                AND status = 'STARTED'
                                AND inactive_at IS NULL");
    $st->execute([$now, $actorUserId, $drawId]);
    return $st->rowCount();
  }

  public function create(int $drawId, string $mode, ?int $baseExecutionId, int $executedByUserId, string $configSnapshotJson): int
  {
    $now = $this->nowVE();

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
    $now = $this->nowVE();
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
