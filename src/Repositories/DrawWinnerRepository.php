<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class DrawWinnerRepository
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }
  private function nowUtc(): string { return gmdate('Y-m-d H:i:s'); }

  public function countActiveByDraw(int $drawId): int
  {
    $st = $this->db->prepare("SELECT COUNT(1) AS c
                              FROM draw_winner
                              WHERE draw_id = ?
                                AND inactive_at IS NULL");
    $st->execute([$drawId]);
    return (int)($st->fetch()['c'] ?? 0);
  }

  public function countVisibleActiveByDraw(int $drawId): int
  {
    $st = $this->db->prepare("SELECT COUNT(1) AS c
                              FROM draw_winner
                              WHERE draw_id = ?
                                AND inactive_at IS NULL
                                AND (reveal_at IS NULL OR reveal_at <= UTC_TIMESTAMP())");
    $st->execute([$drawId]);
    return (int)($st->fetch()['c'] ?? 0);
  }

  public function getMaxRevealAtActiveByDraw(int $drawId): ?string
  {
    $st = $this->db->prepare("SELECT MAX(reveal_at) AS max_reveal_at
                              FROM draw_winner
                              WHERE draw_id = ?
                                AND inactive_at IS NULL");
    $st->execute([$drawId]);
    $row = $st->fetch();
    $v = $row['max_reveal_at'] ?? null;
    return $v !== null && $v !== '' ? (string)$v : null;
  }

  /** @return array<int,array<string,mixed>> */
  public function listByDraw(int $drawId, bool $includeInactive, bool $visibleOnly = false): array
  {
    $sql = "SELECT id, draw_execution_id, draw_id, action_number, winner_order, selected_at,
                   reveal_at, active_from, inactive_at, created_at, updated_at
            FROM draw_winner
            WHERE draw_id = ?";
    $vals = [$drawId];

    if (!$includeInactive) {
      $sql .= " AND inactive_at IS NULL";
    }

    if ($visibleOnly) {
      $sql .= " AND (reveal_at IS NULL OR reveal_at <= UTC_TIMESTAMP())";
    }

    $sql .= " ORDER BY winner_order ASC, selected_at ASC";
    $st = $this->db->prepare($sql);
    $st->execute($vals);
    return $st->fetchAll();
  }

  /** @return array<int,int> actionNumbers */
  public function listActiveActionNumbersByDraw(int $drawId): array
  {
    $st = $this->db->prepare("SELECT action_number
                              FROM draw_winner
                              WHERE draw_id = ?
                                AND inactive_at IS NULL");
    $st->execute([$drawId]);
    $rows = $st->fetchAll();
    return array_map(fn($r) => (int)$r['action_number'], $rows);
  }

  /** @return array<int,int> actionNumbers */
  public function listActiveWinnerActionsByDraw(int $drawId): array
  {
    return $this->listActiveActionNumbersByDraw($drawId);
  }

  public function insertWinner(int $executionId, int $drawId, int $actionNumber, int $winnerOrder, int $actorUserId): int
  {
    $now = $this->nowUtc();
    $sql = "INSERT INTO draw_winner (
              draw_execution_id, draw_id,
              action_number, winner_order, selected_at,
              active_from, inactive_at,
              created_at, updated_at, created_by, updated_by
            ) VALUES (
              ?, ?,
              ?, ?, ?,
              ?, NULL,
              NOW(), NOW(), ?, ?
            )";
    $st = $this->db->prepare($sql);
    $st->execute([
      $executionId,
      $drawId,
      $actionNumber,
      $winnerOrder,
      $now,
      $now,
      $actorUserId,
      $actorUserId,
    ]);
    return (int)$this->db->lastInsertId();
  }



  public function insertWinnerWithRevealAt(
    int $executionId,
    int $drawId,
    int $actionNumber,
    int $winnerOrder,
    string $selectedAtUtc,
    string $revealAtUtc,
    int $actorUserId
  ): int
  {
    $sql = "INSERT INTO draw_winner (
              draw_execution_id, draw_id,
              action_number, winner_order, selected_at,
              reveal_at,
              active_from, inactive_at,
              created_at, updated_at, created_by, updated_by
            ) VALUES (
              ?, ?,
              ?, ?, ?,
              ?,
              ?, NULL,
              NOW(), NOW(), ?, ?
            )";
    $st = $this->db->prepare($sql);
    $st->execute([
      $executionId,
      $drawId,
      $actionNumber,
      $winnerOrder,
      $selectedAtUtc,
      $revealAtUtc,
      $selectedAtUtc,
      $actorUserId,
      $actorUserId,
    ]);
    return (int)$this->db->lastInsertId();
  }

  public function inactivateActiveWinnersByDraw(int $drawId, int $actorUserId): int
  {
    $now = $this->nowUtc();
    $sql = "UPDATE draw_winner
            SET inactive_at = ?,
                updated_at = NOW(),
                updated_by = ?
            WHERE draw_id = ?
              AND inactive_at IS NULL";
    $st = $this->db->prepare($sql);
    $st->execute([$now, $actorUserId, $drawId]);
    return $st->rowCount();
  }
}
