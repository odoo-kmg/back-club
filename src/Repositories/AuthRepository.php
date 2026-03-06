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

  private function roleHasNameColumn(): bool
  {
    static $cached = null;
    if ($cached !== null) return $cached;

    try {
      $st = $this->db->query("SHOW COLUMNS FROM role LIKE 'name'");
      $cached = (bool)$st->fetch();
      return $cached;
    } catch (\Throwable $e) {
      $cached = false;
      return false;
    }
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

  public function findUserById(int $id): ?array
  {
    $st = $this->db->prepare("SELECT id, username, full_name, email, active_from, inactive_at FROM app_user WHERE id = ? LIMIT 1");
    $st->execute([$id]);
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

  /** @return array<int,array{code:string,name:string}> */
  public function getActiveRolesForUser(int $userId): array
  {
    $now = $this->nowUtc();
    $nameExpr = $this->roleHasNameColumn() ? 'COALESCE(r.name, r.code)' : 'r.code';

    $sql = "
      SELECT r.code, {$nameExpr} AS role_name
      FROM user_role ur
      JOIN role r ON r.id = ur.role_id
      WHERE ur.user_id = ?
        AND ur.active_from <= ? AND (ur.inactive_at IS NULL OR ur.inactive_at > ?)
        AND r.active_from  <= ? AND (r.inactive_at  IS NULL OR r.inactive_at  > ?)
      ORDER BY role_name ASC
    ";

    $st = $this->db->prepare($sql);
    $st->execute([$userId, $now, $now, $now, $now]);
    $rows = $st->fetchAll() ?: [];

    return array_map(fn($r) => [
      'code' => (string)$r['code'],
      'name' => (string)$r['role_name'],
    ], $rows);
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

  /** @return array<int,array<string,mixed>> */
  public function listUsers(bool $includeInactive = false): array
  {
    $sql = "
      SELECT id, username, full_name, email, active_from, inactive_at
      FROM app_user
    ";
    if (!$includeInactive) {
      $sql .= " WHERE inactive_at IS NULL OR inactive_at > NOW() ";
    }
    $sql .= " ORDER BY username ASC ";

    $rows = $this->db->query($sql)->fetchAll() ?: [];
    foreach ($rows as &$row) {
      $row['roles'] = $this->getActiveRolesForUser((int)$row['id']);
    }
    unset($row);

    return $rows;
  }

  /** @return array<int,array{code:string,name:string}> */
  public function listRoles(bool $includeInactive = false): array
  {
    $nameExpr = $this->roleHasNameColumn() ? 'COALESCE(name, code)' : 'code';
    $sql = "SELECT id, code, {$nameExpr} AS role_name, active_from, inactive_at FROM role";
    if (!$includeInactive) {
      $sql .= " WHERE inactive_at IS NULL OR inactive_at > NOW() ";
    }
    $sql .= " ORDER BY role_name ASC ";

    $rows = $this->db->query($sql)->fetchAll() ?: [];
    return array_map(fn($r) => [
      'id' => (int)$r['id'],
      'code' => (string)$r['code'],
      'name' => (string)$r['role_name'],
      'activeFrom' => $r['active_from'],
      'inactiveAt' => $r['inactive_at'],
    ], $rows);
  }

  public function patchUser(int $id, array $fields, int $actorUserId): void
  {
    if (count($fields) === 0) return;

    $sets = [];
    $params = [];
    foreach ($fields as $col => $val) {
      $sets[] = "{$col} = ?";
      $params[] = $val;
    }
    $sets[] = "updated_at = NOW()";
    $sets[] = "updated_by = ?";
    $params[] = $actorUserId;
    $params[] = $id;

    $sql = "UPDATE app_user SET " . implode(', ', $sets) . " WHERE id = ?";
    $st = $this->db->prepare($sql);
    $st->execute($params);
  }

  public function deactivateActiveUserRoles(int $userId, int $actorUserId): void
  {
    $st = $this->db->prepare("
      UPDATE user_role
      SET inactive_at = NOW(), updated_at = NOW(), updated_by = ?
      WHERE user_id = ?
        AND (inactive_at IS NULL OR inactive_at > NOW())
    ");
    $st->execute([$actorUserId, $userId]);
  }

  public function deactivateUser(int $id, int $actorUserId): void
  {
    $st = $this->db->prepare("
      UPDATE app_user
      SET inactive_at = NOW(), updated_at = NOW(), updated_by = ?
      WHERE id = ?
        AND (inactive_at IS NULL OR inactive_at > NOW())
    ");
    $st->execute([$actorUserId, $id]);
  }
}
