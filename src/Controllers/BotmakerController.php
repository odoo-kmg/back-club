<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Http\HttpException;
use App\Repositories\DrawParticipantRepository;
use App\Repositories\DrawRepository;
use App\Repositories\DrawWinnerRepository;
use PDO;

final class BotmakerController
{
  private PDO $db;

  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  public function listDrawsForRegistration(Response $res): void
  {
    $now = date('Y-m-d H:i:s');
    $sql = "SELECT id, event_id, resource_type_id, participation_scope_id, name, resource_context,
                   reg_open_at, reg_close_at, winners_count, pick_interval_seconds,
                   use_start_date, use_end_date, status, active_from, inactive_at
            FROM `draw`
            WHERE status = 'REG_OPEN'
              AND active_from <= ? AND (inactive_at IS NULL OR inactive_at > ?)
              AND reg_open_at <= ? AND reg_close_at > ?
            ORDER BY id DESC";

    $st = $this->db->prepare($sql);
    $st->execute([$now, $now, $now, $now]);
    $rows = $st->fetchAll();

    $items = array_map(function (array $r): array {
      return [
        'id' => (int)$r['id'],
        'eventId' => (int)$r['event_id'],
        'resourceTypeId' => (int)$r['resource_type_id'],
        'participationScopeId' => (int)$r['participation_scope_id'],
        'name' => (string)$r['name'],
        'resourceContext' => $r['resource_context'],
        'regOpenAt' => $r['reg_open_at'],
        'regCloseAt' => $r['reg_close_at'],
        'winnersCount' => (int)$r['winners_count'],
        'pickIntervalSeconds' => (int)$r['pick_interval_seconds'],
        'useStartDate' => $r['use_start_date'],
        'useEndDate' => $r['use_end_date'],
        'status' => (string)$r['status'],
        'activeFrom' => $r['active_from'],
        'inactiveAt' => $r['inactive_at'],
      ];
    }, $rows);

    $res->json(200, ['ok' => true, 'data' => $items, 'error' => null]);
  }

  public function registerParticipant(int $drawId, int $serviceUserId, string $serviceUsername, Request $req, Response $res): void
  {
    $participantController = new ParticipantController($this->db);
    $participantController->registerMachine($drawId, $serviceUserId, $serviceUsername, 'WHATSAPP', $req, $res);
  }

  public function listDrawsForResults(Request $req, Response $res): void
  {
    $includeInactive = $this->toBool($req->query('includeInactive', 'false'));
    $dateFrom = $this->nullableDateTime($req->query('dateFrom', null), 'dateFrom');
    $dateTo = $this->nullableDateTime($req->query('dateTo', null), 'dateTo');

    $where = ["d.status IN ('EXECUTING','FINISHED')"];
    $vals = [];

    if (!$includeInactive) {
      $now = date('Y-m-d H:i:s');
      $where[] = 'd.active_from <= ? AND (d.inactive_at IS NULL OR d.inactive_at > ?)';
      $vals[] = $now;
      $vals[] = $now;
    }

    if ($dateFrom !== null) {
      $where[] = 'd.reg_open_at >= ?';
      $vals[] = $dateFrom;
    }
    if ($dateTo !== null) {
      $where[] = 'd.reg_open_at <= ?';
      $vals[] = $dateTo;
    }

    $sql = "SELECT d.id, d.event_id, d.resource_type_id, d.participation_scope_id, d.name, d.resource_context,
                   d.reg_open_at, d.reg_close_at, d.winners_count, d.pick_interval_seconds,
                   d.use_start_date, d.use_end_date, d.status, d.active_from, d.inactive_at,
                   SUM(CASE WHEN w.id IS NOT NULL AND (w.reveal_at IS NULL OR w.reveal_at <= NOW()) THEN 1 ELSE 0 END) AS visible_winners_count,
                   MAX(w.reveal_at) AS max_reveal_at
            FROM `draw` d
            LEFT JOIN draw_winner w
              ON w.draw_id = d.id
             AND w.inactive_at IS NULL
            WHERE " . implode(' AND ', $where) . "
            GROUP BY d.id, d.event_id, d.resource_type_id, d.participation_scope_id, d.name, d.resource_context,
                     d.reg_open_at, d.reg_close_at, d.winners_count, d.pick_interval_seconds,
                     d.use_start_date, d.use_end_date, d.status, d.active_from, d.inactive_at
            HAVING visible_winners_count > 0
            ORDER BY d.reg_open_at DESC, d.id DESC";

    $st = $this->db->prepare($sql);
    $st->execute($vals);
    $rows = $st->fetchAll();

    $items = array_map(function (array $r): array {
      return [
        'id' => (int)$r['id'],
        'eventId' => (int)$r['event_id'],
        'resourceTypeId' => (int)$r['resource_type_id'],
        'participationScopeId' => (int)$r['participation_scope_id'],
        'name' => (string)$r['name'],
        'resourceContext' => $r['resource_context'],
        'regOpenAt' => $r['reg_open_at'],
        'regCloseAt' => $r['reg_close_at'],
        'winnersCount' => (int)$r['winners_count'],
        'pickIntervalSeconds' => (int)$r['pick_interval_seconds'],
        'useStartDate' => $r['use_start_date'],
        'useEndDate' => $r['use_end_date'],
        'status' => (string)$r['status'],
        'activeFrom' => $r['active_from'],
        'inactiveAt' => $r['inactive_at'],
        'visibleWinnersCount' => (int)$r['visible_winners_count'],
        'maxRevealAt' => $r['max_reveal_at'],
      ];
    }, $rows);

    $res->json(200, ['ok' => true, 'data' => $items, 'error' => null]);
  }

  public function getResultByAction(int $drawId, Request $req, Response $res): void
  {
    $rawAction = trim((string)$req->query('actionNumber', ''));
    if ($rawAction === '' || !ctype_digit($rawAction) || (int)$rawAction <= 0) {
      throw new HttpException(400, 'VALIDATION', 'actionNumber es requerido y debe ser numérico');
    }
    $actionNumber = (int)$rawAction;

    $drawRepo = new DrawRepository($this->db);
    $draw = $drawRepo->getById($drawId);
    if (!$draw) {
      throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');
    }

    $winnerRepo = new DrawWinnerRepository($this->db);
    if ($winnerRepo->countVisibleActiveByDraw($drawId) <= 0) {
      throw new HttpException(422, 'RESULTS_NOT_AVAILABLE', 'El sorteo aún no tiene resultados visibles');
    }

    $participantRepo = new DrawParticipantRepository($this->db);
    $participant = $participantRepo->getActiveByDrawAndAction($drawId, $actionNumber);
    if (!$participant) {
      throw new HttpException(404, 'ACTION_NOT_REGISTERED_IN_DRAW', 'La acción no participó en ese sorteo');
    }

    $winner = $winnerRepo->findVisibleWinnerByDrawAndAction($drawId, $actionNumber);

    $res->json(200, [
      'ok' => true,
      'data' => [
        'drawId' => $drawId,
        'drawName' => (string)$draw['name'],
        'actionNumber' => $actionNumber,
        'isWinner' => $winner !== null,
        'result' => $winner ? 'Asignado' : 'NoAsignado',
        'winnerOrder' => $winner ? (int)$winner['winner_order'] : null,
        'selectedAt' => $winner['selected_at'] ?? null,
        'channel' => $participant['channel'],
        'phoneE164' => $participant['phone_e164'],
      ],
      'error' => null,
    ]);
  }

  public function exportResultsNotification(int $drawId, Request $req, Response $res): void
  {
    $format = strtolower(trim((string)$req->query('format', 'csv')));
    if (!in_array($format, ['csv', 'json'], true)) {
      throw new HttpException(422, 'UNSUPPORTED_FORMAT', 'format inválido (csv|json)');
    }

    $drawRepo = new DrawRepository($this->db);
    $draw = $drawRepo->getById($drawId);
    if (!$draw) {
      throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');
    }

    $participantRepo = new DrawParticipantRepository($this->db);
    $rows = $participantRepo->listNotificationRowsByDraw($drawId);

    $items = array_map(function (array $r): array {
      return [
        'actionNumber' => (int)$r['action_number'],
        'phoneE164' => $r['phone_e164'],
        'result' => ((int)$r['is_winner'] === 1) ? 'Asignado' : 'NoAsignado',
        'winnerOrder' => $r['winner_order'] !== null ? (int)$r['winner_order'] : null,
        'selectedAt' => $r['selected_at'],
      ];
    }, $rows);

    if ($format === 'json') {
      $res->json(200, [
        'ok' => true,
        'data' => [
          'generatedAt' => date('c'),
          'draw' => [
            'id' => (int)$draw['id'],
            'name' => (string)$draw['name'],
            'status' => (string)$draw['status'],
          ],
          'items' => $items,
        ],
        'error' => null,
      ]);
      return;
    }

    $filename = 'draw_' . $drawId . '_results_notification_' . date('Ymd_His') . '.csv';
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    if ($out === false) {
      throw new HttpException(500, 'SERVER_ERROR', 'No se pudo generar el archivo');
    }

    fputcsv($out, ['accion', 'telefono', 'resultado', 'orden_ganador', 'fecha_seleccion']);
    foreach ($items as $item) {
      fputcsv($out, [
        $item['actionNumber'],
        $item['phoneE164'],
        $item['result'],
        $item['winnerOrder'],
        $item['selectedAt'],
      ]);
    }
    fclose($out);
  }

  private function toBool($v): bool
  {
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1', 'true', 'yes', 'y', 'on'], true);
  }

  private function nullableDateTime($value, string $field): ?string
  {
    if ($value === null) return null;
    $s = trim((string)$value);
    if ($s === '') return null;

    $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $s) ?: \DateTime::createFromFormat('Y-m-d', $s);
    if (!$dt) {
      throw new HttpException(400, 'VALIDATION', $field . ' inválido. Use YYYY-MM-DD o YYYY-MM-DD HH:MM:SS');
    }

    if (strlen($s) === 10) {
      return $field === 'dateTo' ? ($s . ' 23:59:59') : ($s . ' 00:00:00');
    }

    return $dt->format('Y-m-d H:i:s');
  }
}
