<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Http\HttpException;
use App\Repositories\ParticipationScopeRepository;
use App\Security\AuthContext;
use PDO;

final class ParticipationScopeController
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }

  public function list(Request $req, Response $res): void
  {
    $includeInactive = $this->toBool($req->query('includeInactive', 'false'));
    $eventId = $this->nullableInt($req->query('eventId', null));
    $resourceTypeId = $this->nullableInt($req->query('resourceTypeId', null));

    $repo = new ParticipationScopeRepository($this->db);
    $items = $repo->list($eventId, $resourceTypeId, $includeInactive);

    $res->json(200, ['ok' => true, 'data' => $this->mapList($items), 'error' => null]);
  }

  public function create(AuthContext $ctx, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $eventId = (int)($body['eventId'] ?? 0);
    $resourceTypeId = (int)($body['resourceTypeId'] ?? 0);
    $code = trim((string)($body['code'] ?? ''));
    $name = trim((string)($body['name'] ?? ''));
    $description = array_key_exists('description', $body) ? (string)$body['description'] : null;

    if ($eventId <= 0 || $resourceTypeId <= 0 || $code === '' || $name === '') {
      throw new HttpException(400, 'VALIDATION', 'eventId, resourceTypeId, code y name son requeridos');
    }

    $activeFrom = isset($body['activeFrom']) ? $this->toDateTime($body['activeFrom']) : gmdate('Y-m-d H:i:s');
    $inactiveAt = array_key_exists('inactiveAt', $body) ? $this->nullableDateTime($body['inactiveAt']) : null;

    $repo = new ParticipationScopeRepository($this->db);

    try {
      $id = $repo->create([
        'event_id' => $eventId,
        'resource_type_id' => $resourceTypeId,
        'code' => $code,
        'name' => $name,
        'description' => $description,
        'active_from' => $activeFrom,
        'inactive_at' => $inactiveAt,
      ], $ctx->userId);

      $res->json(201, ['ok' => true, 'data' => ['id' => $id], 'error' => null]);
    } catch (\PDOException $e) {
      if ((int)($e->errorInfo[1] ?? 0) === 1062) {
        throw new HttpException(409, 'DUPLICATE', 'code ya existe');
      }
      throw $e;
    }
  }

  public function patch(AuthContext $ctx, int $id, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $fields = [];

    if (array_key_exists('eventId', $body)) $fields['event_id'] = (int)$body['eventId'];
    if (array_key_exists('resourceTypeId', $body)) $fields['resource_type_id'] = (int)$body['resourceTypeId'];
    if (array_key_exists('code', $body)) $fields['code'] = trim((string)$body['code']);
    if (array_key_exists('name', $body)) $fields['name'] = trim((string)$body['name']);
    if (array_key_exists('description', $body)) $fields['description'] = $body['description'] === null ? null : (string)$body['description'];
    if (array_key_exists('activeFrom', $body)) $fields['active_from'] = $this->toDateTime($body['activeFrom']);
    if (array_key_exists('inactiveAt', $body)) $fields['inactive_at'] = $this->nullableDateTime($body['inactiveAt']);

    $repo = new ParticipationScopeRepository($this->db);

    if (!$repo->getById($id)) {
      throw new HttpException(404, 'NOT_FOUND', 'Participation scope no existe');
    }
    if (count($fields) === 0) {
      throw new HttpException(400, 'VALIDATION', 'No hay campos para actualizar');
    }

    try {
      $repo->patch($id, $fields, $ctx->userId);
      $res->json(200, ['ok' => true, 'data' => ['id' => $id], 'error' => null]);
    } catch (\PDOException $e) {
      if ((int)($e->errorInfo[1] ?? 0) === 1062) {
        throw new HttpException(409, 'DUPLICATE', 'code ya existe');
      }
      throw $e;
    }
  }

  private function mapList(array $rows): array
  {
    return array_map(function ($r) {
      return [
        'id' => (int)$r['id'],
        'eventId' => (int)$r['event_id'],
        'resourceTypeId' => (int)$r['resource_type_id'],
        'code' => $r['code'],
        'name' => $r['name'],
        'description' => $r['description'],
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

  private function nullableInt($v): ?int
  {
    if ($v === null || $v === '') return null;
    $n = (int)$v;
    return $n > 0 ? $n : null;
  }

  private function toDateTime($v): string
  {
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
