<?php
declare(strict_types=1);

namespace App\Services;

use App\Integrations\BotmakerTemplateClient;
use App\Repositories\DrawNotificationRepository;
use PDO;

final class DrawNotificationService
{
  private PDO $db;
  private array $config;

  public function __construct(PDO $db, array $config)
  {
    $this->db = $db;
    $this->config = $config;
  }

  /** @param array<string,mixed> $draw @param array<string,mixed> $participantOrRow */
  public function sendAssignedNotification(array $draw, array $participantOrRow, int $winnerOrder, ?int $winnerId, int $actorUserId): array
  {
    $actionNumber = (int)($participantOrRow['action_number'] ?? $participantOrRow['actionNumber'] ?? 0);
    $phone = $this->normalizePhone($participantOrRow['phone_e164'] ?? $participantOrRow['phoneE164'] ?? null);
    $participantId = isset($participantOrRow['id']) ? (int)$participantOrRow['id'] : (isset($participantOrRow['participant_id']) ? (int)$participantOrRow['participant_id'] : null);

    return $this->sendTemplateNotification(
      $draw,
      $participantId,
      $winnerId,
      $actionNumber,
      $phone,
      'ASSIGNED',
      (string)($this->botmakerCfg()['assigned_template'] ?? 'resultado_asignado'),
      [
        '1' => (string)($draw['name'] ?? ''),
        '2' => (string)$actionNumber,
        '3' => (string)$winnerOrder,
      ],
      [
        'drawName' => (string)($draw['name'] ?? ''),
        'actionNumber' => (string)$actionNumber,
        'winnerOrder' => (string)$winnerOrder,
      ],
      sprintf('ASSIGNED:%d:%d:%d', (int)$draw['id'], $actionNumber, $winnerOrder),
      $actorUserId
    );
  }

  /** @param array<string,mixed> $draw @param array<string,mixed> $participantOrRow */
  public function sendNotAssignedNotification(array $draw, array $participantOrRow, int $actorUserId): array
  {
    $actionNumber = (int)($participantOrRow['action_number'] ?? $participantOrRow['actionNumber'] ?? 0);
    $phone = $this->normalizePhone($participantOrRow['phone_e164'] ?? $participantOrRow['phoneE164'] ?? null);
    $participantId = isset($participantOrRow['id']) ? (int)$participantOrRow['id'] : (isset($participantOrRow['participant_id']) ? (int)$participantOrRow['participant_id'] : null);

    return $this->sendTemplateNotification(
      $draw,
      $participantId,
      null,
      $actionNumber,
      $phone,
      'NOT_ASSIGNED',
      (string)($this->botmakerCfg()['not_assigned_template'] ?? 'resultado_no_asignado'),
      [
        '1' => (string)($draw['name'] ?? ''),
        '2' => (string)$actionNumber,
      ],
      [
        'drawName' => (string)($draw['name'] ?? ''),
        'actionNumber' => (string)$actionNumber,
      ],
      sprintf('NOT_ASSIGNED:%d:%d', (int)$draw['id'], $actionNumber),
      $actorUserId
    );
  }

  private function botmakerCfg(): array
  {
    return (array)($this->config['notifications']['botmaker'] ?? []);
  }

  private function normalizePhone($value): ?string
  {
    $s = trim((string)$value);
    return $s !== '' ? $s : null;
  }

  /** @param array<string,mixed> $draw @param array<string,string> $templateVariables @param array<string,string> $namedVariables */
  private function sendTemplateNotification(array $draw, ?int $participantId, ?int $winnerId, int $actionNumber, ?string $phoneE164, string $notificationType, string $templateName, array $templateVariables, array $namedVariables, string $idempotencyKey, int $actorUserId): array
  {
    $repo = new DrawNotificationRepository($this->db);
    $existing = $repo->findByIdempotencyKey($idempotencyKey);

    if ($existing && in_array((string)$existing['status'], ['SENT', 'SKIPPED'], true)) {
      return [
        'status' => 'SKIPPED',
        'reason' => 'ALREADY_PROCESSED',
        'notificationId' => (int)$existing['id'],
      ];
    }

    $payload = [
      'channel' => 'WHATSAPP',
      'templateName' => $templateName,
      'language' => 'es',
      'phoneE164' => $phoneE164,
      'templateVariables' => $templateVariables,
      'namedVariables' => $namedVariables,
      'metadata' => [
        'drawId' => (int)$draw['id'],
        'drawName' => (string)($draw['name'] ?? ''),
        'participantId' => $participantId,
        'winnerId' => $winnerId,
        'notificationType' => $notificationType,
        'actionNumber' => $actionNumber,
      ],
    ];

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($payloadJson === false) $payloadJson = '{"error":"payload_json"}';

    $notificationId = $existing ? (int)$existing['id'] : $repo->create([
      'draw_id' => (int)$draw['id'],
      'participant_id' => $participantId,
      'winner_id' => $winnerId,
      'notification_type' => $notificationType,
      'channel' => 'WHATSAPP',
      'template_name' => $templateName,
      'phone_e164' => $phoneE164,
      'payload_json' => $payloadJson,
      'status' => 'PENDING',
      'idempotency_key' => $idempotencyKey,
    ], $actorUserId);

    if ($phoneE164 === null) {
      $repo->markSkipped($notificationId, 'PHONE_EMPTY', 'Participante sin teléfono E.164', $actorUserId);
      return ['status' => 'SKIPPED', 'reason' => 'PHONE_EMPTY', 'notificationId' => $notificationId];
    }

    $botmakerCfg = $this->botmakerCfg();
    $enabled = (bool)($botmakerCfg['enabled'] ?? false);
    if (!$enabled) {
      $repo->markFailed($notificationId, 'BOTMAKER_DISABLED', 'Integración Botmaker deshabilitada en configuración', null, false, $actorUserId);
      return ['status' => 'FAILED', 'reason' => 'BOTMAKER_DISABLED', 'notificationId' => $notificationId];
    }

    $client = new BotmakerTemplateClient($botmakerCfg);
    $result = $client->sendTemplate($payload);

    if ($result['ok']) {
      $repo->markSent($notificationId, $result['responseBody'] !== '' ? $result['responseBody'] : null, $actorUserId);
      return ['status' => 'SENT', 'notificationId' => $notificationId];
    }

    $repo->markFailed(
      $notificationId,
      (string)($result['errorMessage'] ?? 'BOTMAKER_SEND_FAILED'),
      'No se pudo enviar la plantilla a Botmaker',
      $result['responseBody'] !== '' ? $result['responseBody'] : null,
      true,
      $actorUserId
    );

    return ['status' => 'FAILED', 'reason' => (string)($result['errorMessage'] ?? 'BOTMAKER_SEND_FAILED'), 'notificationId' => $notificationId];
  }
}
