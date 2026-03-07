<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Http\HttpException;
use App\Repositories\EligibilityRangeRepository;
use App\Security\AuthContext;
use PDO;

final class EligibilityRangeController
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }

  public function patch(AuthContext $ctx, int $id, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $repo = new EligibilityRangeRepository($this->db);
    $existing = $repo->getById($id);
    if (!$existing) throw new HttpException(404, 'NOT_FOUND', 'Rango no existe');

    $fields = [];
    if (array_key_exists('fromAction', $body)) $fields['range_from_action'] = (int)$body['fromAction'];
    if (array_key_exists('toAction', $body)) $fields['range_to_action'] = (int)$body['toAction'];
    if (array_key_exists('label', $body)) $fields['label'] = $this->nullableString($body['label']);
    if (array_key_exists('activeFrom', $body)) $fields['active_from'] = $this->toDateTime($body['activeFrom']);
    if (array_key_exists('inactiveAt', $body)) $fields['inactive_at'] = $this->nullableDateTime($body['inactiveAt']);

    if (count($fields) === 0) throw new HttpException(400, 'VALIDATION', 'No hay campos para actualizar');

    $from = $fields['range_from_action'] ?? (int)$existing['range_from_action'];
    $to = $fields['range_to_action'] ?? (int)$existing['range_to_action'];
    if ($from <= 0 || $to <= 0) throw new HttpException(400, 'VALIDATION', 'fromAction y toAction deben ser > 0');
    if ($from > $to) throw new HttpException(400, 'VALIDATION', 'fromAction debe ser <= toAction');

    $repo->patch($id, $fields, $ctx->userId);
    $res->json(200, ['ok' => true, 'data' => ['id' => $id], 'error' => null]);
  }

  private function nullableString($v): ?string
  {
    if ($v === null) return null;
    $s = trim((string)$v);
    return $s === '' ? null : $s;
  }

  private function toDateTime($v): string
  {
    $s = trim((string)$v);
    $s = str_replace('T', ' ', $s);
    $s = preg_replace('/(Z|[+-]\d{2}:?\d{2})$/', '', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) return $s . ':00';
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return $s;
    throw new HttpException(400, 'VALIDATION', 'Fecha-hora inválida (use YYYY-MM-DD HH:MM[:SS] o YYYY-MM-DDTHH:MM[:SS])');
  }

  private function nullableDateTime($v): ?string
  {
    if ($v === null || $v === '') return null;
    return $this->toDateTime($v);
  }
}
