<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\DrawExecutionRepository;
use App\Repositories\DrawRepository;
use App\Repositories\DrawWinnerRepository;
use App\Security\AuthContext;
use PDO;

final class WinnerController
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }

  public function list(int $drawId, AuthContext $ctx, Request $req, Response $res): void
  {
    $includeInactive = $this->toBool($req->query('includeInactive', 'false'));
    $visibleOnly = $this->toBool($req->query('visibleOnly', 'false'));

    // Best-effort: si ya están visibles todos los ganadores programados,
    // persistimos FINISHED antes de responder. No debe romper el endpoint.
    try {
      $this->tryAutoFinishIfNeeded($drawId, $ctx->userId);
    } catch (\Throwable $e) {
      // no-op: listar winners debe seguir funcionando aunque falle el auto-finish
    }

    $repo = new DrawWinnerRepository($this->db);
    $rows = $repo->listByDraw($drawId, $includeInactive, $visibleOnly);

    $items = array_map(function ($r) {
      return [
        'id' => (int)$r['id'],
        'drawId' => (int)$r['draw_id'],
        'drawExecutionId' => (int)$r['draw_execution_id'],
        'actionNumber' => (int)$r['action_number'],
        'winnerOrder' => (int)$r['winner_order'],
        'selectedAt' => $r['selected_at'],
        'revealAt' => $r['reveal_at'] ?? null,
        'activeFrom' => $r['active_from'],
        'inactiveAt' => $r['inactive_at'],
        'createdAt' => $r['created_at'],
        'updatedAt' => $r['updated_at'],
      ];
    }, $rows);

    $res->json(200, ['ok' => true, 'data' => $items, 'error' => null]);
  }

  private function tryAutoFinishIfNeeded(int $drawId, int $actorUserId): void
  {
    $drawRepo = new DrawRepository($this->db);
    $execRepo = new DrawExecutionRepository($this->db);
    $winnerRepo = new DrawWinnerRepository($this->db);

    $draw = $drawRepo->getById($drawId);
    if (!$draw) return;

    $drawStatus = (string)($draw['status'] ?? '');
    if ($drawStatus === 'FINISHED') return;
    if ($drawStatus !== 'EXECUTING') return;

    $startedExecutionId = $execRepo->findStartedExecutionIdByDraw($drawId);
    if ($startedExecutionId === null) {
      return;
    }

    // Auto-finish aplica SOLO a ejecuciones automáticas.
    // Regla:
    // 1) si execution.mode = AUTO => continuar
    // 2) si execution.mode no viene / no es confiable, usar fallback por reveal_at:
    //    - si hay maxRevealAt no nulo => es automático
    //    - si maxRevealAt es null => no auto-finalizar (manual)
    $exec = $execRepo->getById($startedExecutionId);
    $mode = strtoupper((string)($exec['mode'] ?? ''));

    // Fallback robusto: en AUTO los winners programados tienen reveal_at;
    // en manual reveal_at es NULL.
    $maxRevealAt = $winnerRepo->getMaxRevealAtActiveByDraw($drawId);

    $isAutoExecution = ($mode === 'AUTO') || ($maxRevealAt !== null && $maxRevealAt !== '');

    if (!$isAutoExecution) {
        return;
    }

    $totalActive = $winnerRepo->countActiveByDraw($drawId);
    if ($totalActive <= 0) {
      return;
    }

    $visibleActive = $winnerRepo->countVisibleActiveByDraw($drawId);
    // NO recalcular $maxRevealAt aquí; ya fue calculado arriba.
    $targetWinners = (int)($draw['winners_count'] ?? 0);

    $allScheduledVisible = $visibleActive >= $totalActive;
    $targetReached = $targetWinners > 0 ? ($totalActive >= $targetWinners && $visibleActive >= $targetWinners) : $allScheduledVisible;
    $scheduleExpired = false;

    if ($maxRevealAt !== null && $maxRevealAt !== '') {
      $scheduleExpired = strcmp(date('Y-m-d H:i:s'), $maxRevealAt) >= 0;
    }

    // Cierre determinístico (AUTO):
    // 1) si ya están visibles todos los ganadores programados
    // 2) o si ya se alcanzó la meta de winners_count y todos están visibles
    // 3) o si el schedule ya expiró y no queda nada oculto por revelar
    $shouldFinish = $allScheduledVisible || $targetReached || ($scheduleExpired && $visibleActive >= $totalActive);
    if (!$shouldFinish) return;

    $execRepo->markFinished($startedExecutionId, $actorUserId);
    $drawRepo->setStatus($drawId, 'FINISHED', $actorUserId);
  }

  private function toBool($v): bool
  {
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','true','yes','y','on'], true);
  }
}
