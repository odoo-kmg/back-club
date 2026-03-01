<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Http\HttpException;
use App\Repositories\EventRepository;
use App\Security\AuthContext;
use PDO;

final class EventController
{
  private PDO $db;

  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  public function list(Request $req, Response $res): void
  {
    $includeInactive = $this->toBool($req->query('includeInactive', 'false'));
    $repo = new EventRepository($this->db);
    $items = $repo->list($includeInactive);

    $res->json(200, ['ok' => true, 'data' => $this->mapList($items), 'error' => null]);
  }

  public function create(AuthContext $ctx, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $code = trim((string)($body['code'] ?? ''));
    $name = trim((string)($body['name'] ?? ''));

    if ($code === '' || $name === '') {
      throw new HttpException(400, 'VALIDATION', 'code y name son requeridos');
    }

    $startDate = isset($body['startDate']) ? $this->toDate($body['startDate']) : null;
    $endDate   = isset($body['endDate']) ? $this->toDate($body['endDate']) : null;

    $activeFrom = isset($body['activeFrom']) ? $this->toDateTime($body['activeFrom']) : gmdate('Y-m-d H:i:s');
    $inactiveAt = array_key_exists('inactiveAt', $body) ? $this->nullableDateTime($body['inactiveAt']) : null;

    $repo = new EventRepository($this->db);

    try {
      $id = $repo->create([
        'code' => $code,
        'name' => $name,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'active_from' => $activeFrom,
        'inactive_at' => $inactiveAt,
      ], $ctx->userId);

      $res->json(201, ['ok' => true, 'data' => ['id' => $id], 'error' => null]);
    } catch (\PDOException $e) {
      // Duplicate
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

    if (array_key_exists('code', $body)) $fields['code'] = trim((string)$body['code']);
    if (array_key_exists('name', $body)) $fields['name'] = trim((string)$body['name']);
    if (array_key_exists('startDate', $body)) $fields['start_date'] = $this->nullableDate($body['startDate']);
    if (array_key_exists('endDate', $body)) $fields['end_date'] = $this->nullableDate($body['endDate']);
    if (array_key_exists('activeFrom', $body)) $fields['active_from'] = $this->toDateTime($body['activeFrom']);
    if (array_key_exists('inactiveAt', $body)) $fields['inactive_at'] = $this->nullableDateTime($body['inactiveAt']);

    $repo = new EventRepository($this->db);

    // 404 if not found
    if (!$repo->getById($id)) {
      throw new HttpException(404, 'NOT_FOUND', 'Evento no existe');
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
        'code' => $r['code'],
        'name' => $r['name'],
        'startDate' => $r['start_date'],
        'endDate' => $r['end_date'],
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

  private function toDate($v): string
  {
    $s = trim((string)$v);
    // expecting YYYY-MM-DD
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

  private function toDateTime($v): string
  {
    $s = trim((string)$v);
    // Accept ISO with T/Z or mysql format.
    // Normalize: if ISO, strip Z and T.
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
