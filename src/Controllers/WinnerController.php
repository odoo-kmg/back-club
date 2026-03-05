<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\AuditEventRepository;
use App\Repositories\DrawExecutionRepository;
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

    $nowUtc = gmdate('Y-m-d H:i:s');

    // === AUTO-FINISH (best-effort, triggered by polling) ===
    // In shared hosting we don't have background workers, so we "finalize" an AUTO execution
    // when the scheduled reveal window has elapsed.
    $this->tryAutoFinishIfNeeded($drawId, $ctx->userId, $nowUtc);

    $repo = new DrawWinnerRepository($this->db);
    $rows = $repo->listByDraw($drawId, $includeInactive);

    if ($visibleOnly) {
      $rows = array_values(array_filter($rows, function ($r) use ($nowUtc) {
        $revealAt = $r['reveal_at'] ?? null;
        if ($revealAt === null || $revealAt === '') return true;
        return ((string)$revealAt <= $nowUtc);
      }));
    }

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

  private function tryAutoFinishIfNeeded(int $drawId, int $actorUserId, string $nowUtc): void
  {
    try {
      // If draw is already FINISHED, do nothing.
      $st = $this->db->prepare("SELECT status FROM draw WHERE id = ? LIMIT 1");
      $st->execute([$drawId]);
      $draw = $st->fetch();
      if (!$draw) return;
      $drawStatus = (string)($draw['status'] ?? '');
      if ($drawStatus === 'FINISHED') return;

      $execRepo = new DrawExecutionRepository($this->db);
      $startedId = $execRepo->findStartedExecutionIdByDraw($drawId);
      if ($startedId === null) return;

      $exec = $execRepo->getById($startedId);
      if (!$exec) return;
      if ((string)($exec['status'] ?? '') !== 'STARTED') return;
      if (strtoupper((string)($exec['mode'] ?? '')) !== 'AUTO') return;

      $winnerRepo = new DrawWinnerRepository($this->db);
      $maxRevealAt = $winnerRepo->getMaxRevealAtActiveByDraw($drawId);

      // No scheduled winners => finish immediately (edge case: not enough participants).
      if ($maxRevealAt === null) {
        $execRepo->markFinished($startedId, $actorUserId);
        $execRepo->updateDrawStatus($drawId, 'FINISHED', $actorUserId);

        (new AuditEventRepository($this->db))->insert(
          $actorUserId,
          'EXEC_AUTO_FINISH',
          'draw_execution',
          $startedId,
          ['drawId' => $drawId, 'reason' => 'NO_SCHEDULED_WINNERS'],
          $actorUserId
        );
        return;
      }

      // Finish when the reveal schedule has elapsed.
      if ($maxRevealAt <= $nowUtc) {
        $execRepo->markFinished($startedId, $actorUserId);
        $execRepo->updateDrawStatus($drawId, 'FINISHED', $actorUserId);

        (new AuditEventRepository($this->db))->insert(
          $actorUserId,
          'EXEC_AUTO_FINISH',
          'draw_execution',
          $startedId,
          ['drawId' => $drawId, 'scheduleEndAt' => $maxRevealAt],
          $actorUserId
        );
      }
    } catch (\Throwable $e) {
      // best-effort only: never break winners polling due to auto-finish failures
      return;
    }
  }

  private function toBool($v): bool
  {
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','true','yes','y','on'], true);
  }
}
