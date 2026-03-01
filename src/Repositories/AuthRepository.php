<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AuthRepository
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }

  private function nowUtc(): string
  {
    return gmdate('Y-m-d H:i:s');
  }

  public function findActiveUserByUsername(string $username): ?array
  {
    $now = $this->nowUtc();

    $sql = "
      SELECT id, username, password_hash, full_name
      FROM app_user
      WHERE username = ?
        AND active_from <= ?
        AND (inactive_at IS NULL OR inactive_at > ?)
      LIMIT 1
    ";

    $st = $this->db->prepare($sql);
    $st->execute([$username, $now, $now]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public function findActiveUserById(int $id): ?array
  {
    $now = $this->nowUtc();

    $sql = "
      SELECT id, username, full_name
      FROM app_user
      WHERE id = ?
        AND active_from <= ?
        AND (inactive_at IS NULL OR inactive_at > ?)
      LIMIT 1
    ";

    $st = $this->db->prepare($sql);
    $st->execute([$id, $now, $now]);
    $row = $st->fetch();
    return $row ?: null;
  }

  /** @return string[] */
  public function getActiveRoleCodesForUser(int $userId): array
  {
    $now = $this->nowUtc();

    $sql = "
      SELECT r.code
      FROM user_role ur
      JOIN role r ON r.id = ur.role_id
      WHERE ur.user_id = ?
        AND ur.active_from <= ? AND (ur.inactive_at IS NULL OR ur.inactive_at > ?)
        AND r.active_from  <= ? AND (r.inactive_at  IS NULL OR r.inactive_at  > ?)
    ";

    $st = $this->db->prepare($sql);
    $st->execute([$userId, $now, $now, $now, $now]);
    $rows = $st->fetchAll();

    return array_values(array_unique(array_map(fn($x) => $x['code'], $rows)));
  }

  /** @return string[] */
  public function getActivePermissionCodesForUser(int $userId): array
  {
    $now = $this->nowUtc();

    $sql = "
      SELECT p.code
      FROM user_role ur
      JOIN role r ON r.id = ur.role_id
      JOIN role_permission rp ON rp.role_id = r.id
      JOIN permission p ON p.id = rp.permission_id
      WHERE ur.user_id = ?
        AND ur.active_from <= ? AND (ur.inactive_at IS NULL OR ur.inactive_at > ?)
        AND r.active_from  <= ? AND (r.inactive_at  IS NULL OR r.inactive_at  > ?)
        AND rp.active_from <= ? AND (rp.inactive_at IS NULL OR rp.inactive_at > ?)
        AND p.active_from  <= ? AND (p.inactive_at  IS NULL OR p.inactive_at  > ?)
    ";

    $st = $this->db->prepare($sql);
    $st->execute([
      $userId,
      $now, $now,
      $now, $now,
      $now, $now,
      $now, $now
    ]);

    $rows = $st->fetchAll();
    return array_values(array_unique(array_map(fn($x) => $x['code'], $rows)));
  }

  public function createUser(array $data, int $actorUserId): int
  {
    $sql = "
      INSERT INTO app_user (
        username, password_hash, full_name, email,
        active_from, inactive_at,
        created_at, updated_at, created_by, updated_by
      ) VALUES (
        ?, ?, ?, ?,
        ?, ?,
        NOW(), NOW(), ?, ?
      )
    ";

    $st = $this->db->prepare($sql);
    $st->execute([
      $data['username'],
      $data['password_hash'],
      $data['full_name'],
      $data['email'],
      $data['active_from'],
      $data['inactive_at'],
      $actorUserId,
      $actorUserId,
    ]);

    return (int)$this->db->lastInsertId();
  }

  public function assignRoleToUserByCode(int $userId, string $roleCode, int $actorUserId): void
  {
    $now = $this->nowUtc();

    $st = $this->db->prepare("
      SELECT id
      FROM role
      WHERE code = ?
        AND active_from <= ?
        AND (inactive_at IS NULL OR inactive_at > ?)
      LIMIT 1
    ");
    $st->execute([$roleCode, $now, $now]);
    $role = $st->fetch();

    if (!$role) {
      throw new \RuntimeException("Rol inválido/inactivo: {$roleCode}");
    }

    $st2 = $this->db->prepare("
      INSERT INTO user_role (
        user_id, role_id,
        active_from, inactive_at,
        created_at, updated_at, created_by, updated_by
      ) VALUES (
        ?, ?,
        NOW(), NULL,
        NOW(), NOW(), ?, ?
      )
    ");
    $st2->execute([$userId, $role['id'], $actorUserId, $actorUserId]);
  }
}