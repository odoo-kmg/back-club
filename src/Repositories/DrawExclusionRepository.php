<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class DrawExclusionRepository
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }
  private function nowUtc(): string { return gmdate('Y-m-d H:i:s'); }

  /** @return array<int,array{rule_type:string,source_draw_id:int,target_draw_id:int}> */
  public function listActiveByTarget(int $targetDrawId): array
  {
    $now = $this->nowUtc();
    $sql = "SELECT rule_type, source_draw_id, target_draw_id
            FROM draw_exclusion_rule
            WHERE target_draw_id = ?
              AND active_from <= ?
              AND (inactive_at IS NULL OR inactive_at > ?)";
    $st = $this->db->prepare($sql);
    $st->execute([$targetDrawId, $now, $now]);
    $rows = $st->fetchAll();

    return array_map(function ($r) {
      return [
        'rule_type' => (string)$r['rule_type'],
        'source_draw_id' => (int)$r['source_draw_id'],
        'target_draw_id' => (int)$r['target_draw_id'],
      ];
    }, $rows);
  }
}
