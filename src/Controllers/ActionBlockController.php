<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Http\HttpException;
use App\Repositories\ActionBlockRepository;
use App\Security\AuthContext;
use PDO;

final class ActionBlockController
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }

  public function list(Request $req, Response $res): void
  {
    $includeInactive = $this->toBool($req->query('includeInactive', 'false'));

    $scopeType = strtoupper(trim((string)$req->query('scopeType', '')));
    $scopeDrawIdRaw = $req->query('scopeDrawId', null);
    $actionNumberRaw = $req->query('actionNumber', null);

    $filters = [];

    if ($scopeType !== '') {
      if (!in_array($scopeType, ['GLOBAL','DRAW'], true)) {
        throw new HttpException(400, 'VALIDATION', 'scopeType inválido (GLOBAL|DRAW)');
      }
      $filters['scope_type'] = $scopeType;
    }

    if ($scopeDrawIdRaw !== null && $scopeDrawIdRaw !== '') {
      if (!ctype_digit((string)$scopeDrawIdRaw)) {
        throw new HttpException(400, 'VALIDATION', 'scopeDrawId inválido');
      }
      $filters['scope_draw_id'] = (int)$scopeDrawIdRaw;
    } elseif ($scopeType === 'DRAW') {
      // allow list without scopeDrawId too
    }

    if ($actionNumberRaw !== null && $actionNumberRaw !== '') {
      if (!ctype_digit((string)$actionNumberRaw)) {
        throw new HttpException(400, 'VALIDATION', 'actionNumber inválido');
      }
      $filters['action_number'] = (int)$actionNumberRaw;
    }

    $repo = new ActionBlockRepository($this->db);
    $items = $repo->list($filters, $includeInactive);

    $res->json(200, [
      'ok' => true,
      'data' => array_map([$this,'mapRow'], $items),
      'error' => null,
    ]);
  }

  public function create(AuthContext $ctx, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $actionNumber = (int)($body['actionNumber'] ?? 0);
    if ($actionNumber <= 0 || $actionNumber > 7000) {
      throw new HttpException(400, 'VALIDATION', 'actionNumber inválido (1..7000)');
    }

    $scopeType = strtoupper(trim((string)($body['scopeType'] ?? '')));
    if (!in_array($scopeType, ['GLOBAL','DRAW'], true)) {
      throw new HttpException(400, 'VALIDATION', 'scopeType inválido (GLOBAL|DRAW)');
    }

    $scopeDrawId = null;
    if ($scopeType === 'DRAW') {
      $scopeDrawId = (int)($body['scopeDrawId'] ?? 0);
      if ($scopeDrawId <= 0) throw new HttpException(400, 'VALIDATION', 'scopeDrawId requerido para scope DRAW');
    }

    $reason = trim((string)($body['reason'] ?? ''));
    if ($reason === '') throw new HttpException(400, 'VALIDATION', 'reason es requerido');

    $activeFrom = array_key_exists('activeFrom', $body) ? $this->toDateTime($body['activeFrom']) : gmdate('Y-m-d H:i:s');
    $inactiveAt = array_key_exists('inactiveAt', $body) ? $this->nullableDateTime($body['inactiveAt']) : null;

    $repo = new ActionBlockRepository($this->db);

    // Avoid generic DB DUPLICATE by explicit check
    $existing = $repo->findActiveBlock($actionNumber, $scopeType, $scopeDrawId);
    if ($existing) {
      throw new HttpException(409, 'BLOCK_ALREADY_EXISTS', 'Ya existe un bloqueo activo para esa acción/scope');
    }

    $id = $repo->create([
      'action_number' => $actionNumber,
      'scope_type' => $scopeType,
      'scope_draw_id' => $scopeDrawId,
      'reason' => $reason,
      'source_file_import_id' => null,
      'active_from' => $activeFrom,
      'inactive_at' => $inactiveAt,
    ], $ctx->userId);

    $repo->logAudit($id, 'BLOCK', $reason, gmdate('Y-m-d H:i:s'), null, $ctx->userId);

    $res->json(201, ['ok' => true, 'data' => ['id' => $id], 'error' => null]);
  }

  public function patch(int $id, AuthContext $ctx, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $repo = new ActionBlockRepository($this->db);
    $row = $repo->getById($id);
    if (!$row) throw new HttpException(404, 'NOT_FOUND', 'Bloqueo no existe');

    $fields = [];
    if (array_key_exists('reason', $body)) $fields['reason'] = trim((string)$body['reason']);
    if (array_key_exists('activeFrom', $body)) $fields['active_from'] = $this->toDateTime($body['activeFrom']);
    if (array_key_exists('inactiveAt', $body)) $fields['inactive_at'] = $this->nullableDateTime($body['inactiveAt']);

    if (count($fields) === 0) throw new HttpException(400, 'VALIDATION', 'No hay campos para actualizar');

    $repo->patch($id, $fields, $ctx->userId);

    $reasonForAudit = $fields['reason'] ?? 'PATCH';
    $repo->logAudit($id, 'UPDATE_REASON', (string)$reasonForAudit, gmdate('Y-m-d H:i:s'), null, $ctx->userId);

    $res->json(200, ['ok' => true, 'data' => ['id' => $id], 'error' => null]);
  }

  public function unblock(int $id, AuthContext $ctx, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $reason = trim((string)($body['reason'] ?? ''));
    if ($reason === '') throw new HttpException(400, 'VALIDATION', 'reason es requerido');

    $repo = new ActionBlockRepository($this->db);
    $row = $repo->getById($id);
    if (!$row) throw new HttpException(404, 'NOT_FOUND', 'Bloqueo no existe');

    if ($row['inactive_at'] !== null) {
      $res->json(200, ['ok' => true, 'data' => ['id' => $id, 'inactiveAt' => $row['inactive_at']], 'error' => null]);
      return;
    }

    $now = gmdate('Y-m-d H:i:s');
    $repo->unblock($id, $now, $ctx->userId);
    $repo->logAudit($id, 'UNBLOCK', $reason, $now, null, $ctx->userId);

    $res->json(200, ['ok' => true, 'data' => ['id' => $id, 'inactiveAt' => $now], 'error' => null]);
  }

  private function mapRow(array $r): array
  {
    return [
      'id' => (int)$r['id'],
      'actionNumber' => (int)$r['action_number'],
      'scopeType' => $r['scope_type'],
      'scopeDrawId' => $r['scope_draw_id'] !== null ? (int)$r['scope_draw_id'] : null,
      'reason' => $r['reason'],
      'sourceFileImportId' => $r['source_file_import_id'] !== null ? (int)$r['source_file_import_id'] : null,
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
