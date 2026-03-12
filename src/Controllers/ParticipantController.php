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

  /**
   * Public (no-auth) registration endpoint.
   * Forces channel = WEB and uses a service user for audit fields.
   */
  public function registerPublic(int $drawId, int $serviceUserId, string $serviceUsername, Request $req, Response $res): void
  {
    if ($serviceUserId <= 0) {
      throw new HttpException(500, 'SERVER_ERROR', 'Configuración incompleta');
    }

    $ctx = new AuthContext($serviceUserId, $serviceUsername, 'Servicio - Registro Web', [], []);

    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');
    // Force WEB channel regardless of client payload.
    $body['channel'] = 'WEB';

    $this->registerInternal($drawId, $ctx, $body, $res);
  }

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
    $this->registerInternal($drawId, $ctx, $body, $res);
  }

  private function registerInternal(int $drawId, AuthContext $ctx, array $body, Response $res): void
  {
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
    $email = $this->normalizeEmail($person['email'] ?? null);
    $phone = $this->normalizePhone($person['phoneE164'] ?? null);

    $document = is_array($body['document'] ?? null) ? $body['document'] : [];
    $documentType = $this->nullableTrim($document['type'] ?? null);
    $documentNumber = $this->nullableTrim($document['number'] ?? null);

    if ($channel === 'WEB') {
      if ($documentType === null || $documentNumber === null) {
        throw new HttpException(400, 'DOCUMENT_REQUIRED', 'Documento requerido para registro web');
      }
    }

    $normalizedDocumentType = null;
    $normalizedDocumentNumber = null;
    $documentKey = null;
    if ($documentType !== null || $documentNumber !== null) {
      if ($documentType === null || $documentNumber === null) {
        throw new HttpException(400, 'DOCUMENT_INVALID', 'Debe indicar tipo y número de documento');
      }
      $normalizedDocumentType = $this->normalizeDocumentType($documentType);
      $normalizedDocumentNumber = $this->normalizeDocumentNumber($documentNumber);
      $documentKey = $this->buildDocumentKey($normalizedDocumentType, $normalizedDocumentNumber);
    }

    $now = date('Y-m-d H:i:s');

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

    // Strong validation only for WEB landing.
    if ($channel === 'WEB') {
      $shareholderController = new ShareholderController($this->db);
      $shareholderController->validateActionDocumentMatch($actionNumber, (string)$normalizedDocumentType, (string)$normalizedDocumentNumber);
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
      'document_type' => $normalizedDocumentType,
      'document_number' => $normalizedDocumentNumber,
      'document_key' => $documentKey,
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

    $now = date('Y-m-d H:i:s');
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
      'document' => [
        'type' => $r['document_type'],
        'number' => $r['document_number'],
        'key' => $r['document_key'],
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

  private function normalizeDocumentType(string $type): string
  {
    $value = strtoupper(trim($type));
    if (!in_array($value, ['V', 'E', 'J'], true)) {
      throw new HttpException(400, 'DOCUMENT_INVALID', 'Tipo de documento inválido');
    }
    return $value;
  }

  private function normalizeDocumentNumber(string $value): string
  {
    $normalized = strtoupper(trim(preg_replace('/[^A-Z0-9]+/', '', (string)$value)));
    if ($normalized === '') {
      throw new HttpException(400, 'DOCUMENT_INVALID', 'Número de documento inválido');
    }
    if (strlen($normalized) < 6 || strlen($normalized) > 15) {
      throw new HttpException(400, 'DOCUMENT_INVALID', 'Número de documento inválido');
    }
    return $normalized;
  }

  private function buildDocumentKey(string $type, string $number): string
  {
    return $type . '-' . $number;
  }

  private function normalizeEmail($value): ?string
  {
    $email = $this->nullableTrim($value);
    if ($email === null) return null;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new HttpException(400, 'VALIDATION', 'email inválido');
    }
    return $email;
  }

  private function normalizePhone($value): ?string
  {
    if ($value === null) return null;
    $digits = preg_replace('/\D+/', '', (string)$value);
    if ($digits === '') return null;
    if (strlen($digits) < 10 || strlen($digits) > 15) {
      throw new HttpException(400, 'VALIDATION', 'phoneE164 inválido');
    }
    return $digits;
  }

  private function isWithinNow(string $from, string $to, string $now): bool
  {
    return ($from <= $now) && ($to > $now);
  }

  private function isActiveNow(string $activeFrom, $inactiveAt, string $now): bool
  {
    if ($activeFrom > $now) return false;
    if ($inactiveAt === null || $inactiveAt === '') return true;
    return ((string)$inactiveAt > $now);
  }
}
