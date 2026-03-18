<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class DrawNotificationRepository
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }

  public function findByIdempotencyKey(string $key): ?array
  {
    $st = $this->db->prepare("SELECT * FROM draw_notification_log WHERE idempotency_key = ? LIMIT 1");
    $st->execute([$key]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public function create(array $data, int $actorUserId): int
  {
    $sql = "INSERT INTO draw_notification_log (
              draw_id, participant_id, winner_id, notification_type, channel,
              template_name, phone_e164, payload_json, provider_response_json,
              status, idempotency_key, error_code, error_message,
              retry_count, sent_at,
              created_at, updated_at, created_by, updated_by
            ) VALUES (
              ?, ?, ?, ?, ?,
              ?, ?, ?, NULL,
              ?, ?, NULL, NULL,
              0, NULL,
              NOW(), NOW(), ?, ?
            )";

    $st = $this->db->prepare($sql);
    $st->execute([
      (int)$data['draw_id'],
      $data['participant_id'] !== null ? (int)$data['participant_id'] : null,
      $data['winner_id'] !== null ? (int)$data['winner_id'] : null,
      (string)$data['notification_type'],
      (string)($data['channel'] ?? 'WHATSAPP'),
      (string)$data['template_name'],
      $data['phone_e164'],
      $data['payload_json'],
      (string)$data['status'],
      (string)$data['idempotency_key'],
      $actorUserId,
      $actorUserId,
    ]);

    return (int)$this->db->lastInsertId();
  }

  public function markSent(int $id, ?string $providerResponseJson, int $actorUserId): void
  {
    $st = $this->db->prepare("UPDATE draw_notification_log
      SET status = 'SENT', provider_response_json = ?, sent_at = NOW(),
          error_code = NULL, error_message = NULL,
          updated_at = NOW(), updated_by = ?
      WHERE id = ?");
    $st->execute([$providerResponseJson, $actorUserId, $id]);
  }

  public function markFailed(int $id, string $errorCode, string $errorMessage, ?string $providerResponseJson, bool $incrementRetry, int $actorUserId): void
  {
    $sql = "UPDATE draw_notification_log
            SET status = 'FAILED', provider_response_json = ?,
                error_code = ?, error_message = ?,
                retry_count = retry_count + " . ($incrementRetry ? '1' : '0') . ",
                updated_at = NOW(), updated_by = ?
            WHERE id = ?";
    $st = $this->db->prepare($sql);
    $st->execute([$providerResponseJson, $errorCode, $errorMessage, $actorUserId, $id]);
  }

  public function markSkipped(int $id, string $errorCode, string $errorMessage, int $actorUserId): void
  {
    $st = $this->db->prepare("UPDATE draw_notification_log
      SET status = 'SKIPPED', error_code = ?, error_message = ?,
          updated_at = NOW(), updated_by = ?
      WHERE id = ?");
    $st->execute([$errorCode, $errorMessage, $actorUserId, $id]);
  }
}
