<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Http\HttpException;
use App\Repositories\DrawRepository;
use App\Repositories\DrawWinnerRepository;
use PDO;

final class ExportController
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }

  public function exportJson(int $drawId, Request $req, Response $res): void
  {
    $format = strtolower(trim((string)$req->query('format', 'json')));
    if ($format !== 'json') {
      throw new HttpException(422, 'UNSUPPORTED_FORMAT', 'En esta fase solo soportamos format=json');
    }

    $includeInactiveParticipants = $this->toBool($req->query('includeInactiveParticipants', 'false'));
    $includeInactiveWinners = $this->toBool($req->query('includeInactiveWinners', 'false'));

    $drawRepo = new DrawRepository($this->db);
    $draw = $drawRepo->getById($drawId);
    if (!$draw) throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');

    $winnerRepo = new DrawWinnerRepository($this->db);
    $winners = $winnerRepo->listByDraw($drawId, $includeInactiveWinners);

    // Participants: keep it simple (raw query)
    $sql = "SELECT id, draw_id, participation_scope_id, action_number, channel, status,
                   first_name, last_name, email, phone_e164,
                   registered_at, registered_by_user_id,
                   cancel_reason, canceled_by_user_id,
                   active_from, inactive_at, created_at, updated_at
            FROM draw_participant
            WHERE draw_id = ?";
    $vals = [$drawId];
    if (!$includeInactiveParticipants) {
      $sql .= " AND inactive_at IS NULL";
    }
    $sql .= " ORDER BY id ASC";
    $st = $this->db->prepare($sql);
    $st->execute($vals);
    $participants = $st->fetchAll();

    $res->json(200, [
      'ok' => true,
      'data' => [
        'generatedAt' => gmdate('c'),
        'draw' => [
          'id' => (int)$draw['id'],
          'name' => (string)$draw['name'],
          'eventId' => (int)$draw['event_id'],
          'resourceTypeId' => (int)$draw['resource_type_id'],
          'participationScopeId' => (int)$draw['participation_scope_id'],
          'resourceContext' => $draw['resource_context'],
          'regOpenAt' => (string)$draw['reg_open_at'],
          'regCloseAt' => (string)$draw['reg_close_at'],
          'winnersCount' => (int)$draw['winners_count'],
          'pickIntervalSeconds' => (int)$draw['pick_interval_seconds'],
          'status' => (string)$draw['status'],
        ],
        'winners' => array_map(function ($w) {
          return [
            'id' => (int)$w['id'],
            'drawExecutionId' => (int)$w['draw_execution_id'],
            'actionNumber' => (int)$w['action_number'],
            'winnerOrder' => (int)$w['winner_order'],
            'selectedAt' => $w['selected_at'],
          ];
        }, $winners),
        'participants' => array_map(function ($p) {
          return [
            'id' => (int)$p['id'],
            'actionNumber' => (int)$p['action_number'],
            'channel' => $p['channel'],
            'status' => $p['status'],
            'person' => [
              'firstName' => $p['first_name'],
              'lastName' => $p['last_name'],
              'email' => $p['email'],
              'phoneE164' => $p['phone_e164'],
            ],
            'registeredAt' => $p['registered_at'],
            'inactiveAt' => $p['inactive_at'],
          ];
        }, $participants),
      ],
      'error' => null,
    ]);
  }

  private function toBool($v): bool
  {
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','true','yes','y','on'], true);
  }
}
