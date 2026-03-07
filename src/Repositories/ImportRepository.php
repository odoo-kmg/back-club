<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ImportRepository
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }
  private function nowUtc(): string { return date('Y-m-d H:i:s'); }

  private function nowVE(): string { return $this->nowUtc(); }

  public function createImport(array $data, int $actorUserId): int
  {
    $sql = "INSERT INTO file_import (
              import_type, original_filename, uploaded_at, uploaded_by_user_id,
              process_status, rows_total, rows_ok, rows_error, notes,
              active_from, inactive_at,
              created_at, updated_at, created_by, updated_by
            ) VALUES (
              ?, ?, ?, ?,
              ?, NULL, NULL, NULL, ?,
              ?, NULL,
              NOW(), NOW(), ?, ?
            )";
    $st = $this->db->prepare($sql);
    $now = $this->nowVE();
    $st->execute([
      (string)$data['import_type'],
      (string)$data['original_filename'],
      (string)$data['uploaded_at'],
      (int)$data['uploaded_by_user_id'],
      (string)$data['process_status'],
      $data['notes'],
      $now,
      $actorUserId,
      $actorUserId,
    ]);
    return (int)$this->db->lastInsertId();
  }

  public function setImportResult(int $importId, string $status, int $total, int $ok, int $err, ?string $notes, int $actorUserId): void
  {
    $sql = "UPDATE file_import
            SET process_status = ?,
                rows_total = ?,
                rows_ok = ?,
                rows_error = ?,
                notes = ?,
                updated_at = NOW(),
                updated_by = ?
            WHERE id = ?";
    $st = $this->db->prepare($sql);
    $st->execute([$status, $total, $ok, $err, $notes, $actorUserId, $importId]);
  }

  public function getImportById(int $id): ?array
  {
    $sql = "SELECT id, import_type, original_filename, uploaded_at, uploaded_by_user_id,
                   process_status, rows_total, rows_ok, rows_error, notes,
                   active_from, inactive_at, created_at, updated_at
            FROM file_import
            WHERE id = ?
            LIMIT 1";
    $st = $this->db->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public function insertRow(array $row, int $actorUserId): void
  {
    $sql = "INSERT INTO file_import_row (
              file_import_id, row_number, action_number,
              scope_type, scope_draw_id,
              operation, reason,
              result_status, error_message,
              active_from, inactive_at,
              created_at, updated_at, created_by, updated_by
            ) VALUES (
              ?, ?, ?,
              ?, ?,
              ?, ?,
              ?, ?,
              ?, NULL,
              NOW(), NOW(), ?, ?
            )";
    $st = $this->db->prepare($sql);
    $st->execute([
      (int)$row['file_import_id'],
      (int)$row['row_number'],
      $row['action_number'],
      $row['scope_type'],
      $row['scope_draw_id'],
      (string)$row['operation'],
      $row['reason'],
      (string)$row['result_status'],
      $row['error_message'],
      (string)$row['active_from'],
      $actorUserId,
      $actorUserId,
    ]);
  }

  /** @return array<int,array<string,mixed>> */
  public function listRows(int $importId, ?string $statusFilter, int $page, int $pageSize): array
  {
    $where = ['file_import_id = ?'];
    $vals = [$importId];

    if ($statusFilter) {
      $where[] = 'result_status = ?';
      $vals[] = $statusFilter;
    }

    $offset = ($page - 1) * $pageSize;

    $sql = "SELECT id, file_import_id, row_number, action_number, scope_type, scope_draw_id,
                   operation, reason, result_status, error_message,
                   created_at
            FROM file_import_row
            WHERE " . implode(' AND ', $where) . "
            ORDER BY row_number ASC
            LIMIT {$pageSize} OFFSET {$offset}";
    $st = $this->db->prepare($sql);
    $st->execute($vals);
    return $st->fetchAll();
  }
}
