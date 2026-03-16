<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ShareholderRepository
{
  private PDO $db;

  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  public function getById(int $id): ?array
  {
    $sql = "SELECT id, action_number, document_type, document_number, document_key,
                   first_name, last_name, phone_e164, email, source_file_import_id,
                   active_from, inactive_at, created_at, updated_at, created_by, updated_by
            FROM shareholder
            WHERE id = ?
            LIMIT 1";
    $st = $this->db->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public function findActiveByActionNumber(int $actionNumber): ?array
  {
    $sql = "SELECT id, action_number, document_type, document_number, document_key,
                   first_name, last_name, phone_e164, email, source_file_import_id,
                   active_from, inactive_at, created_at, updated_at, created_by, updated_by
            FROM shareholder
            WHERE action_number = ?
              AND inactive_at IS NULL
            ORDER BY id ASC
            LIMIT 1";
    $st = $this->db->prepare($sql);
    $st->execute([$actionNumber]);
    $row = $st->fetch();
    return $row ?: null;
  }

  /** @return array<int,array<string,mixed>> */
  public function findActiveListByActionNumber(int $actionNumber): array
  {
    $sql = "SELECT id, action_number, document_type, document_number, document_key,
                   first_name, last_name, phone_e164, email, source_file_import_id,
                   active_from, inactive_at, created_at, updated_at, created_by, updated_by
            FROM shareholder
            WHERE action_number = ?
              AND inactive_at IS NULL
            ORDER BY id ASC";
    $st = $this->db->prepare($sql);
    $st->execute([$actionNumber]);
    return $st->fetchAll() ?: [];
  }

  public function findActiveByDocumentKey(string $documentKey): ?array
  {
    $sql = "SELECT id, action_number, document_type, document_number, document_key,
                   first_name, last_name, phone_e164, email, source_file_import_id,
                   active_from, inactive_at, created_at, updated_at, created_by, updated_by
            FROM shareholder
            WHERE document_key = ?
              AND inactive_at IS NULL
            LIMIT 1";
    $st = $this->db->prepare($sql);
    $st->execute([$documentKey]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public function findActiveMatch(int $actionNumber, string $documentKey): ?array
  {
    $sql = "SELECT id, action_number, document_type, document_number, document_key,
                   first_name, last_name, phone_e164, email, source_file_import_id,
                   active_from, inactive_at, created_at, updated_at, created_by, updated_by
            FROM shareholder
            WHERE action_number = ?
              AND document_key = ?
              AND inactive_at IS NULL
            LIMIT 1";
    $st = $this->db->prepare($sql);
    $st->execute([$actionNumber, $documentKey]);
    $row = $st->fetch();
    return $row ?: null;
  }

  /** @return array{items: array<int,array<string,mixed>>, total:int} */
  public function list(array $filters, bool $includeInactive): array
  {
    $where = ['1=1'];
    $vals = [];

    if (!$includeInactive) {
      $where[] = 'inactive_at IS NULL';
    }

    if (!empty($filters['status'])) {
      if ((string)$filters['status'] === 'ACTIVE') {
        $where[] = 'inactive_at IS NULL';
      } elseif ((string)$filters['status'] === 'INACTIVE') {
        $where[] = 'inactive_at IS NOT NULL';
      }
    }

    if (!empty($filters['search'])) {
      $search = trim((string)$filters['search']);
      if ($search !== '') {
        if (ctype_digit($search)) {
          $where[] = '(action_number = ? OR document_number LIKE ? OR document_key LIKE ?)';
          $vals[] = (int)$search;
          $vals[] = '%' . $search . '%';
          $vals[] = '%' . $search . '%';
        } else {
          $where[] = '(document_key LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)';
          $vals[] = '%' . $search . '%';
          $vals[] = '%' . $search . '%';
          $vals[] = '%' . $search . '%';
          $vals[] = '%' . $search . '%';
        }
      }
    }

    $sqlBase = 'FROM shareholder WHERE ' . implode(' AND ', $where);

    $st = $this->db->prepare("SELECT COUNT(1) AS c {$sqlBase}");
    $st->execute($vals);
    $total = (int)($st->fetch()['c'] ?? 0);

    $page = max(1, (int)($filters['page'] ?? 1));
    $pageSize = min(200, max(1, (int)($filters['page_size'] ?? 50)));
    $offset = ($page - 1) * $pageSize;

    $sql = "SELECT id, action_number, document_type, document_number, document_key,
                   first_name, last_name, phone_e164, email, source_file_import_id,
                   active_from, inactive_at, created_at, updated_at, created_by, updated_by
            {$sqlBase}
            ORDER BY inactive_at IS NULL DESC, action_number ASC, id DESC
            LIMIT {$pageSize} OFFSET {$offset}";
    $st2 = $this->db->prepare($sql);
    $st2->execute($vals);

    return ['items' => $st2->fetchAll(), 'total' => $total];
  }

  public function create(array $data, int $actorUserId): int
  {
    $sql = "INSERT INTO shareholder (
              action_number, document_type, document_number, document_key,
              first_name, last_name, phone_e164, email, source_file_import_id,
              active_from, inactive_at,
              created_at, updated_at, created_by, updated_by
            ) VALUES (
              ?, ?, ?, ?,
              ?, ?, ?, ?, ?,
              ?, NULL,
              NOW(), NOW(), ?, ?
            )";
    $st = $this->db->prepare($sql);
    $st->execute([
      (int)$data['action_number'],
      (string)$data['document_type'],
      (string)$data['document_number'],
      (string)$data['document_key'],
      $data['first_name'],
      $data['last_name'],
      $data['phone_e164'],
      $data['email'],
      $data['source_file_import_id'],
      (string)$data['active_from'],
      $actorUserId,
      $actorUserId,
    ]);

    return (int)$this->db->lastInsertId();
  }

  public function updateActive(int $id, array $data, int $actorUserId): void
  {
    $sql = "UPDATE shareholder
            SET action_number = ?,
                document_type = ?,
                document_number = ?,
                document_key = ?,
                first_name = ?,
                last_name = ?,
                phone_e164 = ?,
                email = ?,
                source_file_import_id = ?,
                updated_at = NOW(),
                updated_by = ?
            WHERE id = ?
              AND inactive_at IS NULL";
    $st = $this->db->prepare($sql);
    $st->execute([
      (int)$data['action_number'],
      (string)$data['document_type'],
      (string)$data['document_number'],
      (string)$data['document_key'],
      $data['first_name'],
      $data['last_name'],
      $data['phone_e164'],
      $data['email'],
      $data['source_file_import_id'],
      $actorUserId,
      $id,
    ]);
  }

  public function deactivate(int $id, string $inactiveAt, int $actorUserId): void
  {
    $sql = "UPDATE shareholder
            SET inactive_at = ?,
                updated_at = NOW(),
                updated_by = ?
            WHERE id = ?
              AND inactive_at IS NULL";
    $st = $this->db->prepare($sql);
    $st->execute([$inactiveAt, $actorUserId, $id]);
  }

  public function sameBusinessData(array $current, array $incoming): bool
  {
    return (int)$current['action_number'] === (int)$incoming['action_number']
      && (string)$current['document_type'] === (string)$incoming['document_type']
      && (string)$current['document_number'] === (string)$incoming['document_number']
      && (string)$current['document_key'] === (string)$incoming['document_key']
      && $this->norm($current['first_name']) === $this->norm($incoming['first_name'])
      && $this->norm($current['last_name']) === $this->norm($incoming['last_name'])
      && $this->norm($current['phone_e164']) === $this->norm($incoming['phone_e164'])
      && $this->norm($current['email']) === $this->norm($incoming['email']);
  }

  private function norm($v): ?string
  {
    if ($v === null) return null;
    $s = trim((string)$v);
    return $s === '' ? null : $s;
  }
}
