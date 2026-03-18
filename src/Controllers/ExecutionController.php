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
use App\Repositories\AuditEventRepository;
use App\Security\AuthContext;
use App\Services\DrawNotificationService;
use PDO;

final class ExecutionController
{
  private PDO $db;
  private array $config;
  public function __construct(PDO $db, array $config) { $this->db = $db; $this->config = $config; }

  public function start(int $drawId, AuthContext $ctx, Request $req, Response $res): void
  {
    // Optional body: { "mode": "NEW"|"AUTO" }
    // AUTO mode: backend precomputes winners and reveals them over time (see draw_winner.reveal_at).
    $body = $req->json() ?: [];
    $mode = strtoupper(trim((string)($body['mode'] ?? 'NEW')));
    if (!in_array($mode, ['NEW', 'AUTO'], true)) {
      throw new HttpException(400, 'VALIDATION', 'mode inválido (usa NEW o AUTO)');
    }

    $drawRepo = new DrawRepository($this->db);
    $draw = $drawRepo->getById($drawId);
    if (!$draw) throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');

    $now = date('Y-m-d H:i:s');
    if (!$this->isActiveNow((string)$draw['active_from'], $draw['inactive_at'], $now)) {
      throw new HttpException(422, 'DRAW_INACTIVE', 'Sorteo inactivo');
    }

    if ((string)$draw['status'] === 'REG_OPEN') {
      throw new HttpException(422, 'DRAW_NOT_READY', 'Debes cerrar el registro antes de iniciar el sorteo');
    }

    $execRepo = new DrawExecutionRepository($this->db);

    // === LOCK: only one STARTED execution per draw ===
    $startedId = $execRepo->findStartedExecutionIdByDraw($drawId);
    if ($startedId !== null) {
      $res->json(409, [
        'ok' => false,
        'data' => ['executionId' => $startedId],
        'error' => ['code' => 'EXECUTION_ALREADY_STARTED', 'message' => 'Ya existe una ejecución STARTED para este sorteo'],
      ]);
      return;
    }

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

    $executionId = $execRepo->create($drawId, $mode, null, $ctx->userId, $snapshot);
    $execRepo->updateDrawStatus($drawId, 'EXECUTING', $ctx->userId);

    (new AuditEventRepository($this->db))->insert(
      $ctx->userId,
      'EXEC_START',
      'draw_execution',
      $executionId,
      ['drawId' => $drawId],
      $ctx->userId
    );

    // AUTO: precompute winners now, but reveal them gradually based on reveal_at.
    if ($mode === 'AUTO') {
      $intervalSec = (int)$draw['pick_interval_seconds'];
      if ($intervalSec <= 0) $intervalSec = 2;

      try {
        $result = $this->precomputeWinnersAuto($drawId, $executionId, $ctx->userId, $intervalSec);
      } catch (\Throwable $e) {
        // No dejar el sorteo colgado en EXECUTING si falla la programación inicial.
        try {
          $execRepo->markFinished($executionId, $ctx->userId);
        } catch (\Throwable $ignore) {}
        try {
          $execRepo->updateDrawStatus($drawId, (string)$draw['status'], $ctx->userId);
        } catch (\Throwable $ignore) {}
        throw $e;
      }

      $res->json(201, [
        'ok' => true,
        'data' => [
          'executionId' => $executionId,
          'status' => 'STARTED',
          'mode' => 'AUTO',
          'intervalSeconds' => $intervalSec,
          'scheduledWinners' => $result['scheduledWinners'],
          'scheduleStartAt' => $result['scheduleStartAt'],
          'scheduleEndAt' => $result['scheduleEndAt'],
        ],
        'error' => null,
      ]);
      return;
    }

    $res->json(201, [
      'ok' => true,
      'data' => ['executionId' => $executionId, 'status' => 'STARTED', 'mode' => 'NEW'],
      'error' => null,
    ]);
  }

  public function resume(int $drawId, AuthContext $ctx, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $executionId = (int)($body['executionId'] ?? $body['baseExecutionId'] ?? 0);
    if ($executionId <= 0) throw new HttpException(400, 'VALIDATION', 'executionId es requerido');

    $drawRepo = new DrawRepository($this->db);
    $draw = $drawRepo->getById($drawId);
    if (!$draw) throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');

    $now = date('Y-m-d H:i:s');
    if (!$this->isActiveNow((string)$draw['active_from'], $draw['inactive_at'], $now)) {
      throw new HttpException(422, 'DRAW_INACTIVE', 'Sorteo inactivo');
    }

    if ((string)$draw['status'] === 'REG_OPEN') {
      throw new HttpException(422, 'DRAW_NOT_READY', 'Debes cerrar el registro antes de reanudar el sorteo');
    }

    $execRepo = new DrawExecutionRepository($this->db);

    // lock: if another execution is STARTED and it's not this one => conflict
    $startedId = $execRepo->findStartedExecutionIdByDraw($drawId);
    if ($startedId !== null && $startedId !== $executionId) {
      $res->json(409, [
        'ok' => false,
        'data' => ['executionId' => $startedId],
        'error' => ['code' => 'EXECUTION_ALREADY_STARTED', 'message' => 'Ya existe otra ejecución STARTED para este sorteo'],
      ]);
      return;
    }

    $exec = $execRepo->getById($executionId);
    if (!$exec) throw new HttpException(404, 'NOT_FOUND', 'Ejecución no existe');
    if ((int)$exec['draw_id'] !== $drawId) throw new HttpException(409, 'CONFLICT', 'La ejecución no pertenece a ese sorteo');

    /**
     * ✅ Regla de negocio:
     * En este sistema, pick_interval_seconds > 0 implica sorteo AUTO.
     * No confiamos ciegamente en draw_execution.mode porque ejecuciones históricas
     * pudieron quedar grabadas como NEW aunque realmente fueron AUTO.
     */
    $mode = ((int)$draw['pick_interval_seconds'] > 0) ? 'AUTO' : 'NEW';

    // Autocorregir el mode persistido para futuras reanudaciones / auditoría.
    if (strtoupper((string)($exec['mode'] ?? '')) !== $mode) {
      $stFixMode = $this->db->prepare("
        UPDATE draw_execution
          SET mode = ?,
              updated_at = NOW(),
              updated_by = ?
        WHERE id = ?
      ");
      $stFixMode->execute([$mode, $ctx->userId, $executionId]);
    }

    $execRepo->markStarted($executionId, $ctx->userId);
    $execRepo->updateDrawStatus($drawId, 'EXECUTING', $ctx->userId);

    (new AuditEventRepository($this->db))->insert(
      $ctx->userId,
      'EXEC_RESUME',
      'draw_execution',
      $executionId,
      ['drawId' => $drawId, 'mode' => $mode],
      $ctx->userId
    );

    if ($mode === 'AUTO') {
      $intervalSec = (int)$draw['pick_interval_seconds'];
      if ($intervalSec <= 0) $intervalSec = 2;

      $result = $this->precomputeRemainingWinnersAuto($drawId, $executionId, $ctx->userId, $intervalSec);

      $res->json(200, [
        'ok' => true,
        'data' => [
          'executionId' => $executionId,
          'status' => 'STARTED',
          'mode' => 'AUTO',
          'intervalSeconds' => $intervalSec,
          'scheduledWinners' => $result['scheduledWinners'],
          'scheduleStartAt' => $result['scheduleStartAt'],
          'scheduleEndAt' => $result['scheduleEndAt'],
        ],
        'error' => null,
      ]);
      return;
    }

    $res->json(200, [
      'ok' => true,
      'data' => ['executionId' => $executionId, 'status' => 'STARTED', 'mode' => 'NEW'],
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

    $winnerRepo = new DrawWinnerRepository($this->db);
    $voided = $winnerRepo->inactivateActiveWinnersByDraw($drawId, $ctx->userId);

    $execRepo = new DrawExecutionRepository($this->db);
    // ensure no parallel started execution remains
    $execRepo->finishAllStartedByDraw($drawId, $ctx->userId);

    $now = date('Y-m-d H:i:s');
    $snapshot = json_encode([
      'drawId' => $drawId,
      'restartReason' => $reason,
      'baseExecutionId' => $baseExecutionId,
      'voidedWinners' => $voided,
      'restartedAt' => $now,
      'executedByUserId' => $ctx->userId,
    ], JSON_UNESCAPED_UNICODE);
    if ($snapshot === false) $snapshot = '{"error":"snapshot"}';

    $executionId = $execRepo->create($drawId, 'RESTART', $baseExecutionId, $ctx->userId, $snapshot);
    $execRepo->updateDrawStatus($drawId, 'EXECUTING', $ctx->userId);

    (new AuditEventRepository($this->db))->insert(
      $ctx->userId,
      'EXEC_RESTART',
      'draw_execution',
      $executionId,
      ['drawId' => $drawId, 'voidedWinners' => $voided, 'reason' => $reason],
      $ctx->userId
    );

    $res->json(201, [
      'ok' => true,
      'data' => ['executionId' => $executionId, 'voidedWinners' => $voided, 'status' => 'STARTED'],
      'error' => null,
    ]);
  }

  public function pickNext(int $drawId, int $executionId, AuthContext $ctx, Request $req, Response $res): void
  {
    $execRepo = new DrawExecutionRepository($this->db);

    // lock: only active STARTED execution can pick
    $startedId = $execRepo->findStartedExecutionIdByDraw($drawId);
    if ($startedId !== null && $startedId !== $executionId) {
      $res->json(409, [
        'ok' => false,
        'data' => ['executionId' => $startedId],
        'error' => ['code' => 'EXECUTION_NOT_ACTIVE', 'message' => 'Esta ejecución no es la activa para el sorteo'],
      ]);
      return;
    }

    $exec = $execRepo->getById($executionId);
    if (!$exec) throw new HttpException(404, 'NOT_FOUND', 'Ejecución no existe');
    if ((int)$exec['draw_id'] !== $drawId) throw new HttpException(409, 'CONFLICT', 'Ejecución no pertenece al sorteo');
    if ((string)$exec['status'] !== 'STARTED') throw new HttpException(422, 'EXECUTION_NOT_STARTED', 'Ejecución no está STARTED');

    if (strtoupper((string)($exec['mode'] ?? 'NEW')) === 'AUTO') {
      throw new HttpException(422, 'AUTO_MODE', 'Esta ejecución está en modo AUTO. Los ganadores se generan al iniciar y se revelan por tiempo.');
    }

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

    $participants = $this->listActiveParticipantsActionNumbers($drawId);
    $alreadyWinners = array_flip($winnerRepo->listActiveActionNumbersByDraw($drawId));

    $rangeRepo = new EligibilityRangeRepository($this->db);
    $ranges = $rangeRepo->listByDraw($drawId, false);

    $exclusionRepo = new DrawExclusionRepository($this->db);
    $rules = $exclusionRepo->listActiveByTarget($drawId);
    $excludedByRules = $this->resolveExcludedActionsByRules($rules);

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
      $res->json(200, [
        'ok' => true,
        'data' => ['finished' => true, 'reason' => 'NO_ELIGIBLE_PARTICIPANTS'],
        'error' => null,
      ]);
      return;
    }

    $maxAttempts = 5;
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
      $idx = random_int(0, count($eligible) - 1);
      $picked = $eligible[$idx];

      try {
        $winnerOrder = $currentWinners + 1;
        $winnerId = $winnerRepo->insertWinner($executionId, $drawId, $picked, $winnerOrder, $ctx->userId);

        (new AuditEventRepository($this->db))->insert(
          $ctx->userId,
          'EXEC_PICK_NEXT',
          'draw_winner',
          $winnerId,
          ['drawId' => $drawId, 'executionId' => $executionId, 'actionNumber' => $picked, 'winnerOrder' => $winnerOrder],
          $ctx->userId
        );

        try {
          $participant = (new \App\Repositories\DrawParticipantRepository($this->db))->getActiveByDrawAndAction($drawId, $picked);
          if ($participant) {
            (new DrawNotificationService($this->db, $this->config))->sendAssignedNotification($draw, $participant, $winnerOrder, $winnerId, $ctx->userId);
          }
        } catch (\Throwable $notifyError) {
          // La notificación no puede romper la ejecución del sorteo manual.
        }

        $res->json(200, [
          'ok' => true,
          'data' => [
            'winnerOrder' => $winnerOrder,
            'actionNumber' => $picked,
            'selectedAt' => date('Y-m-d H:i:s'),
          ],
          'error' => null,
        ]);
        return;
      } catch (\PDOException $e) {
        $sqlState = (string)($e->errorInfo[0] ?? '');
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        if ($sqlState === '23000' && $driverCode === 1062) {
          $currentWinners = $winnerRepo->countActiveByDraw($drawId);
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

    (new AuditEventRepository($this->db))->insert(
      $ctx->userId,
      'EXEC_FINISH',
      'draw_execution',
      $executionId,
      ['drawId' => $drawId],
      $ctx->userId
    );

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
    if (count($ranges) === 0) return true;
    foreach ($ranges as $r) {
      $from = (int)$r['range_from_action'];
      $to = (int)$r['range_to_action'];
      if ($actionNumber >= $from && $actionNumber <= $to) return true;
    }
    return false;
  }

  /** @return array<int,bool> */
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
          $excluded[(int)$row['action_number']] = true;
        }
      } elseif ($type === 'WINNER') {
        $st = $this->db->prepare("SELECT action_number
                                  FROM draw_winner
                                  WHERE draw_id = ?
                                    AND inactive_at IS NULL");
        $st->execute([$sourceDrawId]);
        foreach ($st->fetchAll() as $row) {
          $excluded[(int)$row['action_number']] = true;
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

  /**
   * AUTO mode: precompute winners upfront using cryptographically-secure RNG (random_int)
   * and schedule reveal with draw_winner.reveal_at.
   *
   * Why this design: shared hosting has no cron/daemon. We cannot reliably run background
   * processes that pick one winner every N seconds. Instead, we select winners once,
   * persist them, and reveal progressively using reveal_at timestamps.
   *
   * @return array{scheduledWinners:int,scheduleStartAt:string,scheduleEndAt:string}
   */
  private function precomputeWinnersAuto(int $drawId, int $executionId, int $actorUserId, int $intervalSeconds): array
  {
    $drawRepo = new DrawRepository($this->db);
    $draw = $drawRepo->getById($drawId);
    if (!$draw) throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');

    $winnersCount = (int)$draw['winners_count'];
    if ($winnersCount <= 0) throw new HttpException(422, 'DRAW_NOT_READY', 'winnersCount inválido');

    $winnerRepo = new DrawWinnerRepository($this->db);
    $existing = $winnerRepo->countActiveByDraw($drawId);
    if ($existing > 0) {
      // Safety: avoid mixing schedules. Caller should restart if they want a new run.
      throw new HttpException(409, 'WINNERS_ALREADY_EXIST', 'Ya existen ganadores activos para este sorteo. Usa restart para reiniciar.');
    }

    $participants = $this->listActiveParticipantsActionNumbers($drawId);
    $rangeRepo = new EligibilityRangeRepository($this->db);
    $ranges = $rangeRepo->listByDraw($drawId, false);

    $exclusionRepo = new DrawExclusionRepository($this->db);
    $rules = $exclusionRepo->listActiveByTarget($drawId);
    $excludedByRules = $this->resolveExcludedActionsByRules($rules);

    $blockRepo = new ActionBlockRepository($this->db);

    $eligible = [];
    foreach ($participants as $actionNumber) {
      if (isset($excludedByRules[$actionNumber])) continue;
      if (!$this->isEligibleByRanges($actionNumber, $ranges)) continue;
      if ($blockRepo->isActionBlocked($actionNumber, $drawId)) continue;
      $eligible[] = $actionNumber;
    }

    if (count($eligible) === 0) {
      return [
        'scheduledWinners' => 0,
        'scheduleStartAt' => date('Y-m-d H:i:s'),
        'scheduleEndAt' => date('Y-m-d H:i:s'),
      ];
    }

    // Fisher–Yates shuffle with random_int for unbiased permutation.
    for ($i = count($eligible) - 1; $i > 0; $i--) {
      $j = random_int(0, $i);
      if ($i !== $j) {
        $tmp = $eligible[$i];
        $eligible[$i] = $eligible[$j];
        $eligible[$j] = $tmp;
      }
    }

    $pickedList = array_slice($eligible, 0, min($winnersCount, count($eligible)));
    $nowUtc = date('Y-m-d H:i:s');
    $scheduleStartAt = $nowUtc;

    $this->db->beginTransaction();
    try {
      $scheduled = 0;
      foreach ($pickedList as $idx => $actionNumber) {
        $winnerOrder = $idx + 1;
        $revealAt = date('Y-m-d H:i:s', time() + (($winnerOrder - 1) * $intervalSeconds));
        $winnerId = $winnerRepo->insertWinnerWithRevealAt(
          $executionId,
          $drawId,
          (int)$actionNumber,
          $winnerOrder,
          $nowUtc,
          $revealAt,
          $actorUserId
        );

        (new AuditEventRepository($this->db))->insert(
          $actorUserId,
          'EXEC_PICK_NEXT',
          'draw_winner',
          $winnerId,
          [
            'drawId' => $drawId,
            'executionId' => $executionId,
            'actionNumber' => (int)$actionNumber,
            'winnerOrder' => $winnerOrder,
            'revealAt' => $revealAt,
            'mode' => 'AUTO',
          ],
          $actorUserId
        );
        $scheduled++;
      }
      $this->db->commit();

      $scheduleEndAt = $scheduled > 0
        ? date('Y-m-d H:i:s', time() + (($scheduled - 1) * $intervalSeconds))
        : $scheduleStartAt;

      return [
        'scheduledWinners' => $scheduled,
        'scheduleStartAt' => $scheduleStartAt,
        'scheduleEndAt' => $scheduleEndAt,
      ];
    } catch (\Throwable $e) {
      $this->db->rollBack();
      throw $e;
    }
  }

  /**
   * AUTO resume: append only the remaining winners to an existing execution.
   * Keeps existing winners and orders, and schedules only the missing ones.
   *
   * @return array{scheduledWinners:int,scheduleStartAt:string,scheduleEndAt:string}
   */
  private function precomputeRemainingWinnersAuto(int $drawId, int $executionId, int $actorUserId, int $intervalSeconds): array
  {
    $drawRepo = new DrawRepository($this->db);
    $draw = $drawRepo->getById($drawId);
    if (!$draw) throw new HttpException(404, 'NOT_FOUND', 'Sorteo no existe');

    $winnersCount = (int)$draw['winners_count'];
    if ($winnersCount <= 0) throw new HttpException(422, 'DRAW_NOT_READY', 'winnersCount inválido');

    $winnerRepo = new DrawWinnerRepository($this->db);
    $existing = $winnerRepo->countActiveByDraw($drawId);
    $remaining = $winnersCount - $existing;

    if ($remaining <= 0) {
      $now = date('Y-m-d H:i:s');
      return [
        'scheduledWinners' => 0,
        'scheduleStartAt' => $now,
        'scheduleEndAt' => $now,
      ];
    }

    $participants = $this->listActiveParticipantsActionNumbers($drawId);
    $alreadyWinners = array_flip($winnerRepo->listActiveActionNumbersByDraw($drawId));

    $rangeRepo = new EligibilityRangeRepository($this->db);
    $ranges = $rangeRepo->listByDraw($drawId, false);

    $exclusionRepo = new DrawExclusionRepository($this->db);
    $rules = $exclusionRepo->listActiveByTarget($drawId);
    $excludedByRules = $this->resolveExcludedActionsByRules($rules);

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
      $now = date('Y-m-d H:i:s');
      return [
        'scheduledWinners' => 0,
        'scheduleStartAt' => $now,
        'scheduleEndAt' => $now,
      ];
    }

    for ($i = count($eligible) - 1; $i > 0; $i--) {
      $j = random_int(0, $i);
      if ($i !== $j) {
        $tmp = $eligible[$i];
        $eligible[$i] = $eligible[$j];
        $eligible[$j] = $tmp;
      }
    }

    $pickedList = array_slice($eligible, 0, min($remaining, count($eligible)));
    $selectedAt = date('Y-m-d H:i:s');

    $baseTs = time();
    $lastRevealAt = $winnerRepo->getMaxRevealAtActiveByDraw($drawId);
    if ($lastRevealAt) {
      $lastRevealTs = strtotime($lastRevealAt);
      if ($lastRevealTs !== false) {
        $baseTs = max($baseTs, $lastRevealTs + $intervalSeconds);
      }
    }

    $scheduleStartAt = date('Y-m-d H:i:s', $baseTs);

    $this->db->beginTransaction();
    try {
      $scheduled = 0;
      foreach ($pickedList as $idx => $actionNumber) {
        $winnerOrder = $existing + $idx + 1;
        $revealAt = date('Y-m-d H:i:s', $baseTs + ($idx * $intervalSeconds));

        $winnerId = $winnerRepo->insertWinnerWithRevealAt(
          $executionId,
          $drawId,
          (int)$actionNumber,
          $winnerOrder,
          $selectedAt,
          $revealAt,
          $actorUserId
        );

        (new AuditEventRepository($this->db))->insert(
          $actorUserId,
          'EXEC_PICK_NEXT',
          'draw_winner',
          $winnerId,
          [
            'drawId' => $drawId,
            'executionId' => $executionId,
            'actionNumber' => (int)$actionNumber,
            'winnerOrder' => $winnerOrder,
            'revealAt' => $revealAt,
            'mode' => 'AUTO_RESUME',
          ],
          $actorUserId
        );

        $scheduled++;
      }

      $this->db->commit();

      $scheduleEndAt = $scheduled > 0
        ? date('Y-m-d H:i:s', $baseTs + (($scheduled - 1) * $intervalSeconds))
        : $scheduleStartAt;

      return [
        'scheduledWinners' => $scheduled,
        'scheduleStartAt' => $scheduleStartAt,
        'scheduleEndAt' => $scheduleEndAt,
      ];
    } catch (\Throwable $e) {
      $this->db->rollBack();
      throw $e;
    }
  }
}
