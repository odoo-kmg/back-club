<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Http\HttpException;
use App\Repositories\DrawRepository;
use App\Repositories\EligibilityRangeRepository;
use App\Repositories\DrawParticipantRepository;
use App\Repositories\ActionBlockRepository;
use App\Security\AuthContext;
use PDO;

final class ParticipantController
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }

  public function listByDraw(int $drawId, Request $req, Response $res): void
  {
    $includeInactive = $this->toBool($req->query('includeInactive', 'false'));
    $status = trim((string)$req->query('status', ''));
    $search = trim((string)$req->query('search', ''));
    $page = (int)$req->query('page', 1);
    $pageSize = (int)$req->query('pageSize', 50);

    $filters = [
      'status' => $status !== '' ? $status : null,
      'action_number' => ctype_digit($search) ? (int)$search : null,
      'page' => $page,
      'page_size' => $pageSize,
    ];

    $repo = new DrawParticipantRepository($this->db);
    $result = $repo->listByDraw($drawId, $filters, $includeInactive);

    $items = array_map([$this, 'mapRow'], $result['items']);

    $res->json(200, [
      'ok' => true,
      'data' => [
        'items' => $items,
        'page' => max(1, $page),
        'pageSize' => min(200, max(1, $pageSize)),
        'total' => $result['total'],
      ],
      'error' => null,
    ]);
  }

  public function register(int $drawId, AuthContext $ctx, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $actionNumber = (int)($body['actionNumber'] ?? 0);
    if ($actionNumber <= 0 || $actionNumber > 7000) {
      throw new HttpException(400, 'VALIDATION', 'actionNumber inválido (1..7000)');
    }

    $channel = strtoupper(trim((string)($body['channel'] ?? 'ASSISTED')));
    if (!in_array($channel, ['ASSISTED','WEB','WHATSAPP'], true)) {
      throw new HttpException(400, 'VALIDATION', 'channel inválido (ASSISTED|WEB|WHATSAPP)');
    }

    $person = is_array($body['person'] ?? null) ? $body['person'] : [];
    $firstName = $this->nullableTrim($person['firstName'] ?? null);
    $lastName = $this->nullableTrim($person['lastName'] ?? null);
    $email = $this->nullableTrim($person['email'] ?? null);
    $phone = $this->nullableTrim($person['phoneE164'] ?? null);

    $now = gmdate('Y-m-d H:i:s');

    // Validate draw exists and is open for registration
    $drawRepo = new DrawRepository($this->db);
    $draw = $drawRepo->getById($drawId);
    if (!$draw) throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');

    // Must be active (by window)
    if (!$this->isActiveNow($draw['active_from'], $draw['inactive_at'], $now)) {
      throw new HttpException(422, 'DRAW_INACTIVE', 'Sorteo inactivo');
    }

    if ((string)$draw['status'] !== 'REG_OPEN') {
      throw new HttpException(422, 'REGISTRATION_CLOSED', 'Registro no está abierto');
    }

    // Must be within registration window configured in draw
    if (!$this->isWithinNow($draw['reg_open_at'], $draw['reg_close_at'], $now)) {
      throw new HttpException(422, 'REGISTRATION_CLOSED', 'Fuera de la ventana de registro');
    }

    $scopeId = (int)$draw['participation_scope_id'];

    // Enforce uniqueness at API-level with explicit error
    $participantRepo = new DrawParticipantRepository($this->db);
    if ($participantRepo->hasActiveParticipantInScope($scopeId, $actionNumber)) {
      throw new HttpException(409, 'ACTION_ALREADY_PARTICIPATING_SCOPE', 'La acción ya participa en este scope');
    }

    // Eligibility ranges
    $rangeRepo = new EligibilityRangeRepository($this->db);
    $ranges = $rangeRepo->listByDraw($drawId, false);
    if (count($ranges) === 0) {
      throw new HttpException(422, 'ACTION_NOT_ELIGIBLE', 'No hay rangos elegibles configurados');
    }
    $eligible = false;
    foreach ($ranges as $r) {
      if ($actionNumber >= (int)$r['range_from_action'] && $actionNumber <= (int)$r['range_to_action']) {
        $eligible = true;
        break;
      }
    }
    if (!$eligible) {
      throw new HttpException(422, 'ACTION_NOT_ELIGIBLE', 'Acción no elegible para este sorteo');
    }

    // Blocks (global/draw)
    $blockRepo = new ActionBlockRepository($this->db);
    if ($blockRepo->isActionBlocked($actionNumber, $drawId)) {
      throw new HttpException(422, 'ACTION_BLOCKED', 'Acción no elegible (bloqueada)');
    }

    $registeredBy = ($channel === 'ASSISTED') ? $ctx->userId : null;

    $id = $participantRepo->create([
      'draw_id' => $drawId,
      'participation_scope_id' => $scopeId,
      'action_number' => $actionNumber,
      'channel' => $channel,
      'status' => 'ACTIVE',
      'first_name' => $firstName,
      'last_name' => $lastName,
      'email' => $email,
      'phone_e164' => $phone,
      'registered_at' => $now,
      'registered_by_user_id' => $registeredBy,
      'active_from' => $now,
    ], $ctx->userId);

    $res->json(201, [
      'ok' => true,
      'data' => [
        'participantId' => $id,
        'drawId' => $drawId,
        'actionNumber' => $actionNumber,
        'registeredAt' => $now,
      ],
      'error' => null,
    ]);
  }

  public function cancel(int $participantId, AuthContext $ctx, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $reason = trim((string)($body['reason'] ?? ''));
    if ($reason === '') throw new HttpException(400, 'VALIDATION', 'reason es requerido');

    $repo = new DrawParticipantRepository($this->db);
    $row = $repo->getById($participantId);
    if (!$row) throw new HttpException(404, 'NOT_FOUND', 'Participación no existe');

    if ((string)$row['status'] === 'CANCELED' || $row['inactive_at'] !== null) {
      // idempotent
      $res->json(200, ['ok' => true, 'data' => ['id' => $participantId, 'status' => 'CANCELED'], 'error' => null]);
      return;
    }

    $now = gmdate('Y-m-d H:i:s');
    $repo->cancel($participantId, $reason, $ctx->userId, $now);

    $res->json(200, ['ok' => true, 'data' => ['id' => $participantId, 'status' => 'CANCELED'], 'error' => null]);
  }

  private function mapRow(array $r): array
  {
    return [
      'id' => (int)$r['id'],
      'drawId' => (int)$r['draw_id'],
      'participationScopeId' => (int)$r['participation_scope_id'],
      'actionNumber' => (int)$r['action_number'],
      'channel' => $r['channel'],
      'status' => $r['status'],
      'person' => [
        'firstName' => $r['first_name'],
        'lastName' => $r['last_name'],
        'email' => $r['email'],
        'phoneE164' => $r['phone_e164'],
      ],
      'registeredAt' => $r['registered_at'],
      'registeredByUserId' => $r['registered_by_user_id'] !== null ? (int)$r['registered_by_user_id'] : null,
      'cancelReason' => $r['cancel_reason'],
      'canceledByUserId' => $r['canceled_by_user_id'] !== null ? (int)$r['canceled_by_user_id'] : null,
      'activeFrom' => $r['active_from'],
      'inactiveAt' => $r['inactive_at'],
      'createdAt' => $r['created_at'],
      'updatedAt' => $r['updated_at'],
    ];
  }

  private function toBool($v): bool
  {
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','true','yes','y','on'], true);
  }

  private function nullableTrim($v): ?string
  {
    if ($v === null) return null;
    $s = trim((string)$v);
    return $s === '' ? null : $s;
  }

  private function isWithinNow(string $from, string $to, string $now): bool
  {
    // All are 'YYYY-MM-DD HH:MM:SS' already in DB and in $now (UTC).
    return ($from <= $now) && ($to > $now);
  }

  private function isActiveNow(string $activeFrom, $inactiveAt, string $now): bool
  {
    if ($activeFrom > $now) return false;
    if ($inactiveAt === null || $inactiveAt === '') return true;
    return ((string)$inactiveAt > $now);
  }
}
