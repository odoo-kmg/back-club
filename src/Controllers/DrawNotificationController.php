<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Response;
use App\Repositories\DrawParticipantRepository;
use App\Repositories\DrawRepository;
use App\Security\AuthContext;
use App\Services\DrawNotificationService;
use PDO;

final class DrawNotificationController
{
  private PDO $db;
  private array $config;

  public function __construct(PDO $db, array $config)
  {
    $this->db = $db;
    $this->config = $config;
  }

  public function notifyResults(int $drawId, AuthContext $ctx, Response $res): void
  {
    $drawRepo = new DrawRepository($this->db);
    $draw = $drawRepo->getById($drawId);
    if (!$draw) throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');
    if ((string)$draw['status'] !== 'FINISHED') {
      throw new HttpException(422, 'DRAW_NOT_FINISHED', 'Solo se pueden notificar resultados de sorteos finalizados');
    }

    $isAutomatic = ((int)($draw['pick_interval_seconds'] ?? 0) > 0);
    $participantRepo = new DrawParticipantRepository($this->db);
    $rows = $participantRepo->listNotificationTargetsByDraw($drawId);
    $service = new DrawNotificationService($this->db, $this->config);

    $summary = [
      'processed' => 0,
      'sent' => 0,
      'failed' => 0,
      'skipped' => 0,
      'assignedSent' => 0,
      'notAssignedSent' => 0,
    ];

    foreach ($rows as $row) {
      $isWinner = (int)($row['is_winner'] ?? 0) === 1;

      if (!$isAutomatic && $isWinner) {
        continue;
      }

      $summary['processed']++;
      if ($isWinner) {
        $result = $service->sendAssignedNotification($draw, $row, (int)$row['winner_order'], null, $ctx->userId);
      } else {
        $result = $service->sendNotAssignedNotification($draw, $row, $ctx->userId);
      }

      $status = (string)($result['status'] ?? 'FAILED');
      if ($status === 'SENT') {
        $summary['sent']++;
        if ($isWinner) $summary['assignedSent']++;
        else $summary['notAssignedSent']++;
      } elseif ($status === 'SKIPPED') {
        $summary['skipped']++;
      } else {
        $summary['failed']++;
      }
    }

    $res->json(200, [
      'ok' => true,
      'data' => [
        'drawId' => $drawId,
        'drawName' => (string)$draw['name'],
        'mode' => $isAutomatic ? 'AUTO' : 'MANUAL',
        'summary' => $summary,
      ],
      'error' => null,
    ]);
  }
}
