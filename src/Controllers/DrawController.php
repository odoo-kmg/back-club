<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Http\HttpException;
use App\Repositories\DrawRepository;
use App\Repositories\EligibilityRangeRepository;
use App\Repositories\ExclusionRuleRepository;
use App\Security\AuthContext;
use PDO;

final class DrawController
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }

  public function list(Request $req, Response $res): void
  {
    $includeInactive = $this->toBool($req->query('includeInactive', 'false'));
    $forRegistration = $this->toBool($req->query('forRegistration', 'false'));

    $filters = [];
    if ($req->query('eventId') !== null && $req->query('eventId') !== '') {
      $filters['event_id'] = (int)$req->query('eventId');
    }
    if ($req->query('status') !== null && $req->query('status') !== '') {
      $filters['status'] = trim((string)$req->query('status'));
    }
    if ($forRegistration) {
      $filters['for_registration'] = true;
    }

    $repo = new DrawRepository($this->db);
    $items = $repo->list($filters, $includeInactive);
    $res->json(200, ['ok' => true, 'data' => $this->mapList($items), 'error' => null]);
  }

  public function get(int $id, Response $res): void
  {
    $repo = new DrawRepository($this->db);
    $row = $repo->getById($id);
    if (!$row) throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');
    $res->json(200, ['ok' => true, 'data' => $this->mapOne($row), 'error' => null]);
  }

  public function create(AuthContext $ctx, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $eventId = (int)($body['eventId'] ?? 0);
    $resourceTypeId = (int)($body['resourceTypeId'] ?? 0);
    $scopeId = (int)($body['participationScopeId'] ?? 0);
    $name = trim((string)($body['name'] ?? ''));

    if ($eventId <= 0 || $resourceTypeId <= 0 || $scopeId <= 0 || $name === '') {
      throw new HttpException(400, 'VALIDATION', 'eventId, resourceTypeId, participationScopeId y name son requeridos');
    }

    $resourceContext = array_key_exists('resourceContext', $body) ? $this->nullableString($body['resourceContext']) : null;

    $regOpenAt = $this->toDateTime($body['regOpenAt'] ?? null, 'regOpenAt requerido');
    $regCloseAt = $this->toDateTime($body['regCloseAt'] ?? null, 'regCloseAt requerido');
    if ($regOpenAt >= $regCloseAt) {
      throw new HttpException(400, 'VALIDATION', 'regOpenAt debe ser menor a regCloseAt');
    }

    $winnersCount = (int)($body['winnersCount'] ?? 0);
    if ($winnersCount <= 0) {
      throw new HttpException(400, 'VALIDATION', 'winnersCount debe ser > 0');
    }

    $pickIntervalSeconds = (int)($body['pickIntervalSeconds'] ?? 2);
    if ($pickIntervalSeconds <= 0) $pickIntervalSeconds = 2;

    $useStartDate = array_key_exists('useStartDate', $body) ? $this->nullableDate($body['useStartDate']) : null;
    $useEndDate   = array_key_exists('useEndDate', $body) ? $this->nullableDate($body['useEndDate']) : null;

    $status = isset($body['status']) ? trim((string)$body['status']) : 'DRAFT';
    if ($status === '') $status = 'DRAFT';

    $activeFrom = array_key_exists('activeFrom', $body) ? $this->toDateTime($body['activeFrom']) : gmdate('Y-m-d H:i:s');
    $inactiveAt = array_key_exists('inactiveAt', $body) ? $this->nullableDateTime($body['inactiveAt']) : null;

    $repo = new DrawRepository($this->db);

    $id = $repo->create([
      'event_id' => $eventId,
      'resource_type_id' => $resourceTypeId,
      'participation_scope_id' => $scopeId,
      'name' => $name,
      'resource_context' => $resourceContext,
      'reg_open_at' => $regOpenAt,
      'reg_close_at' => $regCloseAt,
      'winners_count' => $winnersCount,
      'pick_interval_seconds' => $pickIntervalSeconds,
      'use_start_date' => $useStartDate,
      'use_end_date' => $useEndDate,
      'status' => $status,
      'active_from' => $activeFrom,
      'inactive_at' => $inactiveAt,
    ], $ctx->userId);

    $res->json(201, ['ok' => true, 'data' => ['id' => $id], 'error' => null]);
  }

  public function patch(AuthContext $ctx, int $id, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $repo = new DrawRepository($this->db);
    $existing = $repo->getById($id);
    if (!$existing) throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');

    $fields = [];

    if (array_key_exists('eventId', $body)) $fields['event_id'] = (int)$body['eventId'];
    if (array_key_exists('resourceTypeId', $body)) $fields['resource_type_id'] = (int)$body['resourceTypeId'];
    if (array_key_exists('participationScopeId', $body)) $fields['participation_scope_id'] = (int)$body['participationScopeId'];
    if (array_key_exists('name', $body)) $fields['name'] = trim((string)$body['name']);
    if (array_key_exists('resourceContext', $body)) $fields['resource_context'] = $this->nullableString($body['resourceContext']);
    if (array_key_exists('regOpenAt', $body)) $fields['reg_open_at'] = $this->toDateTime($body['regOpenAt']);
    if (array_key_exists('regCloseAt', $body)) $fields['reg_close_at'] = $this->toDateTime($body['regCloseAt']);
    if (array_key_exists('winnersCount', $body)) $fields['winners_count'] = (int)$body['winnersCount'];
    if (array_key_exists('pickIntervalSeconds', $body)) $fields['pick_interval_seconds'] = (int)$body['pickIntervalSeconds'];
    if (array_key_exists('useStartDate', $body)) $fields['use_start_date'] = $this->nullableDate($body['useStartDate']);
    if (array_key_exists('useEndDate', $body)) $fields['use_end_date'] = $this->nullableDate($body['useEndDate']);
    if (array_key_exists('status', $body)) $fields['status'] = trim((string)$body['status']);
    if (array_key_exists('activeFrom', $body)) $fields['active_from'] = $this->toDateTime($body['activeFrom']);
    if (array_key_exists('inactiveAt', $body)) $fields['inactive_at'] = $this->nullableDateTime($body['inactiveAt']);

    if (count($fields) === 0) throw new HttpException(400, 'VALIDATION', 'No hay campos para actualizar');

    // Validate reg window if provided
    $regOpen = $fields['reg_open_at'] ?? (string)$existing['reg_open_at'];
    $regClose = $fields['reg_close_at'] ?? (string)$existing['reg_close_at'];
    if ($regOpen >= $regClose) {
      throw new HttpException(400, 'VALIDATION', 'regOpenAt debe ser menor a regCloseAt');
    }

    if (isset($fields['winners_count']) && (int)$fields['winners_count'] <= 0) {
      throw new HttpException(400, 'VALIDATION', 'winnersCount debe ser > 0');
    }

    $repo->patch($id, $fields, $ctx->userId);
    $res->json(200, ['ok' => true, 'data' => ['id' => $id], 'error' => null]);
  }

  public function openRegistration(AuthContext $ctx, int $id, Request $req, Response $res): void
  {
    $repo = new DrawRepository($this->db);
    $draw = $repo->getById($id);
    if (!$draw) throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');

    $body = $req->json() ?? [];
    $force = $this->toBool($body['force'] ?? 'false');
    $now = gmdate('Y-m-d H:i:s');

    // Must be active
    if (!($draw['active_from'] <= $now && ($draw['inactive_at'] === null || $draw['inactive_at'] > $now))) {
      if (!$force) throw new HttpException(422, 'DRAW_INACTIVE', 'Sorteo inactivo');
    }

    if (!$force) {
      if ($now < (string)$draw['reg_open_at']) {
        throw new HttpException(422, 'REG_NOT_STARTED', 'Registro aún no inicia');
      }
      if ($now >= (string)$draw['reg_close_at']) {
        throw new HttpException(422, 'REG_ALREADY_CLOSED', 'Registro ya cerró por ventana');
      }
    }

    $repo->setStatus($id, 'REG_OPEN', $ctx->userId);
    $res->json(200, ['ok' => true, 'data' => ['id' => $id, 'status' => 'REG_OPEN'], 'error' => null]);
  }

  public function closeRegistration(AuthContext $ctx, int $id, Response $res): void
  {
    $repo = new DrawRepository($this->db);
    if (!$repo->getById($id)) throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');
    $repo->setStatus($id, 'REG_CLOSED', $ctx->userId);
    $res->json(200, ['ok' => true, 'data' => ['id' => $id, 'status' => 'REG_CLOSED'], 'error' => null]);
  }

  // =========================
  // Eligibility ranges
  // =========================

  public function listEligibilityRanges(int $drawId, Request $req, Response $res): void
  {
    $includeInactive = $this->toBool($req->query('includeInactive', 'false'));
    $drawRepo = new DrawRepository($this->db);
    if (!$drawRepo->getById($drawId)) throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');

    $repo = new EligibilityRangeRepository($this->db);
    $rows = $repo->listByDraw($drawId, $includeInactive);
    $res->json(200, ['ok' => true, 'data' => $this->mapRanges($rows), 'error' => null]);
  }

  public function createEligibilityRange(AuthContext $ctx, int $drawId, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $drawRepo = new DrawRepository($this->db);
    if (!$drawRepo->getById($drawId)) throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');

    $from = (int)($body['fromAction'] ?? 0);
    $to = (int)($body['toAction'] ?? 0);
    if ($from <= 0 || $to <= 0) {
      throw new HttpException(400, 'VALIDATION', 'fromAction y toAction son requeridos');
    }
    if ($from > $to) {
      throw new HttpException(400, 'VALIDATION', 'fromAction debe ser <= toAction');
    }

    $label = array_key_exists('label', $body) ? $this->nullableString($body['label']) : null;
    $activeFrom = array_key_exists('activeFrom', $body) ? $this->toDateTime($body['activeFrom']) : gmdate('Y-m-d H:i:s');
    $inactiveAt = array_key_exists('inactiveAt', $body) ? $this->nullableDateTime($body['inactiveAt']) : null;

    $repo = new EligibilityRangeRepository($this->db);
    $id = $repo->create($drawId, [
      'range_from_action' => $from,
      'range_to_action' => $to,
      'label' => $label,
      'active_from' => $activeFrom,
      'inactive_at' => $inactiveAt,
    ], $ctx->userId);

    $res->json(201, ['ok' => true, 'data' => ['id' => $id], 'error' => null]);
  }

  // =========================
  // Exclusions
  // =========================

  public function listExclusions(int $drawId, Request $req, Response $res): void
  {
    $includeInactive = $this->toBool($req->query('includeInactive', 'false'));
    $drawRepo = new DrawRepository($this->db);
    if (!$drawRepo->getById($drawId)) throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');

    $repo = new ExclusionRuleRepository($this->db);
    $rows = $repo->listBySourceDraw($drawId, $includeInactive);
    $res->json(200, ['ok' => true, 'data' => $this->mapExclusions($rows), 'error' => null]);
  }

  public function createExclusion(AuthContext $ctx, int $drawId, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $drawRepo = new DrawRepository($this->db);
    if (!$drawRepo->getById($drawId)) throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');

    $ruleType = strtoupper(trim((string)($body['ruleType'] ?? '')));
    if (!in_array($ruleType, ['PARTICIPATION', 'WINNER'], true)) {
      throw new HttpException(400, 'VALIDATION', 'ruleType inválido (PARTICIPATION|WINNER)');
    }
    $target = (int)($body['targetDrawId'] ?? 0);
    if ($target <= 0) throw new HttpException(400, 'VALIDATION', 'targetDrawId requerido');
    if ($target === $drawId) throw new HttpException(400, 'VALIDATION', 'targetDrawId no puede ser igual a drawId');

    // Ensure target draw exists
    if (!$drawRepo->getById($target)) throw new HttpException(409, 'FK_CONSTRAINT', 'targetDrawId no existe');

    $activeFrom = array_key_exists('activeFrom', $body) ? $this->toDateTime($body['activeFrom']) : gmdate('Y-m-d H:i:s');
    $inactiveAt = array_key_exists('inactiveAt', $body) ? $this->nullableDateTime($body['inactiveAt']) : null;

    $repo = new ExclusionRuleRepository($this->db);
    try {
      $id = $repo->create($drawId, [
        'rule_type' => $ruleType,
        'target_draw_id' => $target,
        'active_from' => $activeFrom,
        'inactive_at' => $inactiveAt,
      ], $ctx->userId);

      $res->json(201, ['ok' => true, 'data' => ['id' => $id], 'error' => null]);
    } catch (\PDOException $e) {
      if ((int)($e->errorInfo[1] ?? 0) === 1062) {
        throw new HttpException(409, 'DUPLICATE', 'Regla duplicada');
      }
      throw $e;
    }
  }

  private function mapList(array $rows): array
  {
    return array_map(fn($r) => $this->mapOne($r), $rows);
  }

  private function mapOne(array $r): array
  {
    return [
      'id' => (int)$r['id'],
      'eventId' => (int)$r['event_id'],
      'resourceTypeId' => (int)$r['resource_type_id'],
      'participationScopeId' => (int)$r['participation_scope_id'],
      'name' => $r['name'],
      'resourceContext' => $r['resource_context'],
      'regOpenAt' => $r['reg_open_at'],
      'regCloseAt' => $r['reg_close_at'],
      'winnersCount' => (int)$r['winners_count'],
      'pickIntervalSeconds' => (int)$r['pick_interval_seconds'],
      'useStartDate' => $r['use_start_date'],
      'useEndDate' => $r['use_end_date'],
      'status' => $r['status'],
      'activeFrom' => $r['active_from'],
      'inactiveAt' => $r['inactive_at'],
    ];
  }

  private function mapRanges(array $rows): array
  {
    return array_map(function ($r) {
      return [
        'id' => (int)$r['id'],
        'drawId' => (int)$r['draw_id'],
        'fromAction' => (int)$r['range_from_action'],
        'toAction' => (int)$r['range_to_action'],
        'label' => $r['label'],
        'activeFrom' => $r['active_from'],
        'inactiveAt' => $r['inactive_at'],
      ];
    }, $rows);
  }

  private function mapExclusions(array $rows): array
  {
    return array_map(function ($r) {
      return [
        'id' => (int)$r['id'],
        'ruleType' => $r['rule_type'],
        'sourceDrawId' => (int)$r['source_draw_id'],
        'targetDrawId' => (int)$r['target_draw_id'],
        'activeFrom' => $r['active_from'],
        'inactiveAt' => $r['inactive_at'],
      ];
    }, $rows);
  }

  private function toBool($v): bool
  {
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','true','yes','y','on'], true);
  }

  private function nullableString($v): ?string
  {
    if ($v === null) return null;
    $s = trim((string)$v);
    return $s === '' ? null : $s;
  }

  private function toDate($v): string
  {
    $s = trim((string)$v);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
      throw new HttpException(400, 'VALIDATION', 'Fecha inválida (YYYY-MM-DD)');
    }
    return $s;
  }

  private function nullableDate($v): ?string
  {
    if ($v === null || $v === '') return null;
    return $this->toDate($v);
  }

  private function toDateTime($v, string $msg = 'Fecha-hora inválida'): string
  {
    if ($v === null || $v === '') throw new HttpException(400, 'VALIDATION', $msg);
    $s = trim((string)$v);
    $s = str_replace('T', ' ', $s);
    $s = str_replace('Z', '', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return $s;
    throw new HttpException(400, 'VALIDATION', 'Fecha-hora inválida (YYYY-MM-DDTHH:MM:SSZ)');
  }

  private function nullableDateTime($v): ?string
  {
    if ($v === null || $v === '') return null;
    return $this->toDateTime($v);
  }
}
