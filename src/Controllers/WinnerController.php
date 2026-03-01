<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\DrawWinnerRepository;
use PDO;

final class WinnerController
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }

  public function list(int $drawId, Request $req, Response $res): void
  {
    $includeInactive = $this->toBool($req->query('includeInactive', 'false'));

    $repo = new DrawWinnerRepository($this->db);
    $rows = $repo->listByDraw($drawId, $includeInactive);

    $items = array_map(function ($r) {
      return [
        'id' => (int)$r['id'],
        'drawId' => (int)$r['draw_id'],
        'drawExecutionId' => (int)$r['draw_execution_id'],
        'actionNumber' => (int)$r['action_number'],
        'winnerOrder' => (int)$r['winner_order'],
        'selectedAt' => $r['selected_at'],
        'activeFrom' => $r['active_from'],
        'inactiveAt' => $r['inactive_at'],
        'createdAt' => $r['created_at'],
        'updatedAt' => $r['updated_at'],
      ];
    }, $rows);

    $res->json(200, ['ok' => true, 'data' => $items, 'error' => null]);
  }

  private function toBool($v): bool
  {
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','true','yes','y','on'], true);
  }
}
