<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Http\HttpException;
use App\Repositories\DrawRepository;
use App\Repositories\EligibilityRangeRepository;
use App\Repositories\ActionBlockRepository;
use App\Repositories\DrawExecutionRepository;
use App\Repositories\DrawWinnerRepository;
use App\Repositories\DrawExclusionRepository;
use App\Security\AuthContext;
use PDO;

final class ExecutionController
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }

  public function start(int $drawId, AuthContext $ctx, Request $req, Response $res): void
  {
    $drawRepo = new DrawRepository($this->db);
    $draw = $drawRepo->getById($drawId);
    if (!$draw) throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');

    $now = gmdate('Y-m-d H:i:s');
    if (!$this->isActiveNow((string)$draw['active_from'], $draw['inactive_at'], $now)) {
      throw new HttpException(422, 'DRAW_INACTIVE', 'Sorteo inactivo');
    }

    if ((string)$draw['status'] === 'REG_OPEN') {
      throw new HttpException(422, 'DRAW_NOT_READY', 'Debes cerrar el registro antes de iniciar el sorteo');
    }

    // Snapshot minimal (keep it simple)
    $snapshot = json_encode([
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
      'startedAt' => $now,
      'executedByUserId' => $ctx->userId,
    ], JSON_UNESCAPED_UNICODE);

    if ($snapshot === false) $snapshot = '{"error":"snapshot"}';

    $execRepo = new DrawExecutionRepository($this->db);
    $executionId = $execRepo->create($drawId, 'NEW', null, $ctx->userId, $snapshot);
    $execRepo->updateDrawStatus($drawId, 'EXECUTING', $ctx->userId);

    $res->json(201, [
      'ok' => true,
      'data' => ['executionId' => $executionId, 'status' => 'STARTED'],
      'error' => null,
    ]);
  }

  public function resume(int $drawId, AuthContext $ctx, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $executionId = (int)($body['executionId'] ?? $body['baseExecutionId'] ?? 0);
    if ($executionId <= 0) throw new HttpException(400, 'VALIDATION', 'executionId es requerido');

    $execRepo = new DrawExecutionRepository($this->db);
    $exec = $execRepo->getById($executionId);
    if (!$exec) throw new HttpException(404, 'NOT_FOUND', 'Ejecución no existe');

    if ((int)$exec['draw_id'] !== $drawId) {
      throw new HttpException(409, 'CONFLICT', 'La ejecución no pertenece a ese sorteo');
    }

    $execRepo->markStarted($executionId, $ctx->userId);
    $execRepo->updateDrawStatus($drawId, 'EXECUTING', $ctx->userId);

    $res->json(200, [
      'ok' => true,
      'data' => ['executionId' => $executionId, 'status' => 'STARTED'],
      'error' => null,
    ]);
  }

  public function restart(int $drawId, AuthContext $ctx, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $reason = trim((string)($body['reason'] ?? ''));
    if ($reason === '') throw new HttpException(400, 'VALIDATION', 'reason es requerido');

    $baseExecutionId = (int)($body['baseExecutionId'] ?? 0);
    if ($baseExecutionId <= 0) $baseExecutionId = null;

    $drawRepo = new DrawRepository($this->db);
    $draw = $drawRepo->getById($drawId);
    if (!$draw) throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');

    // Inactivate current active winners
    $winnerRepo = new DrawWinnerRepository($this->db);
    $voided = $winnerRepo->inactivateActiveWinnersByDraw($drawId, $ctx->userId);

    $now = gmdate('Y-m-d H:i:s');
    $snapshot = json_encode([
      'drawId' => $drawId,
      'restartReason' => $reason,
      'baseExecutionId' => $baseExecutionId,
      'voidedWinners' => $voided,
      'restartedAt' => $now,
      'executedByUserId' => $ctx->userId,
    ], JSON_UNESCAPED_UNICODE);
    if ($snapshot === false) $snapshot = '{"error":"snapshot"}';

    $execRepo = new DrawExecutionRepository($this->db);
    $executionId = $execRepo->create($drawId, 'RESTART', $baseExecutionId, $ctx->userId, $snapshot);
    $execRepo->updateDrawStatus($drawId, 'EXECUTING', $ctx->userId);

    $res->json(201, [
      'ok' => true,
      'data' => ['executionId' => $executionId, 'voidedWinners' => $voided, 'status' => 'STARTED'],
      'error' => null,
    ]);
  }

  public function pickNext(int $drawId, int $executionId, AuthContext $ctx, Request $req, Response $res): void
  {
    $execRepo = new DrawExecutionRepository($this->db);
    $exec = $execRepo->getById($executionId);
    if (!$exec) throw new HttpException(404, 'NOT_FOUND', 'Ejecución no existe');
    if ((int)$exec['draw_id'] !== $drawId) throw new HttpException(409, 'CONFLICT', 'Ejecución no pertenece al sorteo');
    if ((string)$exec['status'] !== 'STARTED') throw new HttpException(422, 'EXECUTION_NOT_STARTED', 'Ejecución no está STARTED');

    $drawRepo = new DrawRepository($this->db);
    $draw = $drawRepo->getById($drawId);
    if (!$draw) throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');

    $winnersCount = (int)$draw['winners_count'];
    $winnerRepo = new DrawWinnerRepository($this->db);

    $currentWinners = $winnerRepo->countActiveByDraw($drawId);
    if ($currentWinners >= $winnersCount) {
      $res->json(200, ['ok' => true, 'data' => ['finished' => true], 'error' => null]);
      return;
    }

    // Load active participants of draw
    $participants = $this->listActiveParticipantsActionNumbers($drawId);

    // Exclude already winners in this draw
    $alreadyWinners = array_flip($winnerRepo->listActiveActionNumbersByDraw($drawId));

    // Eligibility re-check (in case ranges changed after registration)
    $rangeRepo = new EligibilityRangeRepository($this->db);
    $ranges = $rangeRepo->listByDraw($drawId, false);

    // Exclusions from other draws (target=this draw)
    $exclusionRepo = new DrawExclusionRepository($this->db);
    $rules = $exclusionRepo->listActiveByTarget($drawId);
    $excludedByRules = $this->resolveExcludedActionsByRules($rules);

    // Blocks
    $blockRepo = new ActionBlockRepository($this->db);

    $eligible = [];
    foreach ($participants as $actionNumber) {
      if (isset($alreadyWinners[$actionNumber])) continue;
      if (isset($excludedByRules[$actionNumber])) continue;
      if (!$this->isEligibleByRanges($actionNumber, $ranges)) continue;
      if ($blockRepo->isActionBlocked($actionNumber, $drawId)) continue;
      $eligible[] = $actionNumber;
    }

    if (count($eligible) === 0) {
      // No more eligible participants; finish early.
      $res->json(200, [
        'ok' => true,
        'data' => ['finished' => true, 'reason' => 'NO_ELIGIBLE_PARTICIPANTS'],
        'error' => null,
      ]);
      return;
    }

    // Try insert winner up to N times to mitigate concurrency duplicates.
    $maxAttempts = 5;
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
      $idx = random_int(0, count($eligible) - 1);
      $picked = $eligible[$idx];

      try {
        $winnerOrder = $currentWinners + 1;
        $winnerRepo->insertWinner($executionId, $drawId, $picked, $winnerOrder, $ctx->userId);

        $res->json(200, [
          'ok' => true,
          'data' => [
            'winnerOrder' => $winnerOrder,
            'actionNumber' => $picked,
            'selectedAt' => gmdate('Y-m-d H:i:s'),
          ],
          'error' => null,
        ]);
        return;
      } catch (\PDOException $e) {
        $sqlState = (string)($e->errorInfo[0] ?? '');
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        if ($sqlState === '23000' && $driverCode === 1062) {
          // Someone else inserted; recalc winner count and retry.
          $currentWinners = $winnerRepo->countActiveByDraw($drawId);
          // remove picked and continue
          $eligible = array_values(array_filter($eligible, fn($n) => $n !== $picked));
          if (count($eligible) === 0) break;
          continue;
        }
        throw $e;
      }
    }

    throw new HttpException(409, 'RACE_CONDITION', 'No se pudo seleccionar un ganador por concurrencia. Reintenta.');
  }

  public function finish(int $drawId, int $executionId, AuthContext $ctx, Response $res): void
  {
    $execRepo = new DrawExecutionRepository($this->db);
    $exec = $execRepo->getById($executionId);
    if (!$exec) throw new HttpException(404, 'NOT_FOUND', 'Ejecución no existe');
    if ((int)$exec['draw_id'] !== $drawId) throw new HttpException(409, 'CONFLICT', 'Ejecución no pertenece al sorteo');

    $execRepo->markFinished($executionId, $ctx->userId);
    $execRepo->updateDrawStatus($drawId, 'FINISHED', $ctx->userId);

    $res->json(200, ['ok' => true, 'data' => ['executionId' => $executionId, 'status' => 'FINISHED'], 'error' => null]);
  }

  /** @return array<int,int> */
  private function listActiveParticipantsActionNumbers(int $drawId): array
  {
    $st = $this->db->prepare("SELECT action_number
                              FROM draw_participant
                              WHERE draw_id = ?
                                AND inactive_at IS NULL
                                AND status = 'ACTIVE'");
    $st->execute([$drawId]);
    $rows = $st->fetchAll();
    return array_map(fn($r) => (int)$r['action_number'], $rows);
  }

  private function isEligibleByRanges(int $actionNumber, array $ranges): bool
  {
    if (count($ranges) === 0) return true; // if no ranges, allow (but registration phase enforces). Keep permissive here.
    foreach ($ranges as $r) {
      $from = (int)$r['range_from_action'];
      $to = (int)$r['range_to_action'];
      if ($actionNumber >= $from && $actionNumber <= $to) return true;
    }
    return false;
  }

  /** @return array<int,bool> set of excluded action numbers */
  private function resolveExcludedActionsByRules(array $rules): array
  {
    $excluded = [];

    foreach ($rules as $r) {
      $type = strtoupper((string)$r['rule_type']);
      $sourceDrawId = (int)$r['source_draw_id'];

      if ($type === 'PARTICIPATION') {
        $st = $this->db->prepare("SELECT action_number
                                  FROM draw_participant
                                  WHERE draw_id = ?
                                    AND inactive_at IS NULL
                                    AND status = 'ACTIVE'");
        $st->execute([$sourceDrawId]);
        foreach ($st->fetchAll() as $row) {
          $excluded[(int)$row['action_number']] = True;
        }
      } elseif ($type === 'WINNER') {
        $st = $this->db->prepare("SELECT action_number
                                  FROM draw_winner
                                  WHERE draw_id = ?
                                    AND inactive_at IS NULL");
        $st->execute([$sourceDrawId]);
        foreach ($st->fetchAll() as $row) {
          $excluded[(int)$row['action_number']] = True;
        }
      }
    }

    return $excluded;
  }

  private function isActiveNow(string $activeFrom, $inactiveAt, string $now): bool
  {
    if ($activeFrom > $now) return false;
    if ($inactiveAt === null || $inactiveAt === '') return true;
    return ((string)$inactiveAt > $now);
  }
}
