<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class DrawParticipantRepository
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }

  private function nowUtc(): string { return date('Y-m-d H:i:s'); }

  public function hasActiveParticipantInScope(int $scopeId, int $actionNumber): bool
  {
    $sql = "SELECT id
            FROM draw_participant
            WHERE participation_scope_id = ?
              AND action_number = ?
              AND inactive_at IS NULL
            LIMIT 1";
    $st = $this->db->prepare($sql);
    $st->execute([$scopeId, $actionNumber]);
    return (bool)$st->fetch();
  }

  public function create(array $data, int $actorUserId): int
  {
    $sql = "INSERT INTO draw_participant (
              draw_id, participation_scope_id,
              action_number, channel, status,
              first_name, last_name, email, phone_e164,
              document_type, document_number, document_key,
              registered_at, registered_by_user_id,
              cancel_reason, canceled_by_user_id,
              active_from, inactive_at,
              created_at, updated_at, created_by, updated_by
            ) VALUES (
              ?, ?,
              ?, ?, ?,
              ?, ?, ?, ?,
              ?, ?, ?,
              ?, ?,
              NULL, NULL,
              ?, NULL,
              NOW(), NOW(), ?, ?
            )";

    $st = $this->db->prepare($sql);
    $st->execute([
      (int)$data['draw_id'],
      (int)$data['participation_scope_id'],
      (int)$data['action_number'],
      (string)$data['channel'],
      (string)$data['status'],
      $data['first_name'],
      $data['last_name'],
      $data['email'],
      $data['phone_e164'],
      $data['document_type'],
      $data['document_number'],
      $data['document_key'],
      (string)$data['registered_at'],
      $data['registered_by_user_id'],
      (string)$data['active_from'],
      $actorUserId,
      $actorUserId,
    ]);

    return (int)$this->db->lastInsertId();
  }

  public function getById(int $id): ?array
  {
    $sql = "SELECT id, draw_id, participation_scope_id, action_number, channel, status,
                   first_name, last_name, email, phone_e164,
                   document_type, document_number, document_key,
                   registered_at, registered_by_user_id,
                   cancel_reason, canceled_by_user_id,
                   active_from, inactive_at, created_at, updated_at
            FROM draw_participant
            WHERE id = ?
            LIMIT 1";
    $st = $this->db->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public function getActiveByDrawAndAction(int $drawId, int $actionNumber): ?array
  {
    $sql = "SELECT id, draw_id, participation_scope_id, action_number, channel, status,
                   first_name, last_name, email, phone_e164,
                   document_type, document_number, document_key,
                   registered_at, registered_by_user_id,
                   cancel_reason, canceled_by_user_id,
                   active_from, inactive_at, created_at, updated_at
            FROM draw_participant
            WHERE draw_id = ?
              AND action_number = ?
              AND inactive_at IS NULL
            ORDER BY id DESC
            LIMIT 1";
    $st = $this->db->prepare($sql);
    $st->execute([$drawId, $actionNumber]);
    $row = $st->fetch();
    return $row ?: null;
  }

  /** @return array<int,array<string,mixed>> */
  public function listNotificationRowsByDraw(int $drawId): array
  {
    $sql = "SELECT p.action_number, p.phone_e164,
                   CASE WHEN w.id IS NULL THEN 0 ELSE 1 END AS is_winner,
                   w.winner_order, w.selected_at
            FROM draw_participant p
            LEFT JOIN draw_winner w
              ON w.draw_id = p.draw_id
             AND w.action_number = p.action_number
             AND w.inactive_at IS NULL
            WHERE p.draw_id = ?
              AND p.inactive_at IS NULL
            ORDER BY p.action_number ASC";
    $st = $this->db->prepare($sql);
    $st->execute([$drawId]);
    return $st->fetchAll();
  }

  /** @return array{items: array<int,array<string,mixed>>, total:int} */
  public function listByDraw(int $drawId, array $filters, bool $includeInactive): array
  {
    $where = ['draw_id = ?'];
    $vals = [$drawId];

    if (!$includeInactive) {
      $where[] = "inactive_at IS NULL";
    }

    if (!empty($filters['status'])) {
      $where[] = "status = ?";
      $vals[] = (string)$filters['status'];
    }

    if (!empty($filters['action_number'])) {
      $where[] = "action_number = ?";
      $vals[] = (int)$filters['action_number'];
    }

    $sqlBase = "FROM draw_participant WHERE " . implode(' AND ', $where);

    $st = $this->db->prepare("SELECT COUNT(1) AS c {$sqlBase}");
    $st->execute($vals);
    $total = (int)($st->fetch()['c'] ?? 0);

    $page = max(1, (int)($filters['page'] ?? 1));
    $pageSize = min(200, max(1, (int)($filters['page_size'] ?? 50)));
    $offset = ($page - 1) * $pageSize;

    $sql = "SELECT id, draw_id, participation_scope_id, action_number, channel, status,
                   first_name, last_name, email, phone_e164,
                   document_type, document_number, document_key,
                   registered_at, registered_by_user_id,
                   cancel_reason, canceled_by_user_id,
                   active_from, inactive_at, created_at, updated_at
            {$sqlBase}
            ORDER BY id DESC
            LIMIT {$pageSize} OFFSET {$offset}";

    $st2 = $this->db->prepare($sql);
    $st2->execute($vals);
    $items = $st2->fetchAll();

    return ['items' => $items, 'total' => $total];
  }

  public function cancel(int $participantId, string $reason, int $actorUserId, string $nowUtc): void
  {
    $sql = "UPDATE draw_participant
            SET status = 'CANCELED',
                cancel_reason = ?,
                canceled_by_user_id = ?,
                inactive_at = ?,
                updated_at = NOW(),
                updated_by = ?
            WHERE id = ?";
    $st = $this->db->prepare($sql);
    $st->execute([$reason, $actorUserId, $nowUtc, $actorUserId, $participantId]);
  }
}
