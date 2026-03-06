<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Http\HttpException;
use App\Repositories\AuthRepository;
use App\Security\AuthContext;
use PDO;

final class AdminUserController
{
  private array $config;
  private PDO $db;

  public function __construct(array $config, PDO $db)
  {
    $this->config = $config;
    $this->db = $db;
  }

  public function listUsers(AuthContext $ctx, Request $req, Response $res): void
  {
    $includeInactive = $this->toBool($req->query('includeInactive', 'false'));
    $repo = new AuthRepository($this->db);
    $items = $repo->listUsers($includeInactive);

    $mapped = array_map(function ($r) {
      return [
        'id' => (int)$r['id'],
        'username' => (string)$r['username'],
        'fullName' => (string)$r['full_name'],
        'email' => $r['email'],
        'activeFrom' => $r['active_from'],
        'inactiveAt' => $r['inactive_at'],
        'roles' => $r['roles'] ?? [],
      ];
    }, $items);

    $res->json(200, ['ok' => true, 'data' => $mapped, 'error' => null]);
  }

  public function listRoles(AuthContext $ctx, Request $req, Response $res): void
  {
    $includeInactive = $this->toBool($req->query('includeInactive', 'false'));
    $repo = new AuthRepository($this->db);
    $items = $repo->listRoles($includeInactive);
    $res->json(200, ['ok' => true, 'data' => $items, 'error' => null]);
  }

  public function createUser(AuthContext $ctx, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $fullName = trim((string)($body['fullName'] ?? ''));
    $email = isset($body['email']) ? trim((string)$body['email']) : null;
    $roles = $body['roles'] ?? [];

    $this->validateUsername($username);
    $this->validateFullName($fullName);
    $this->validateEmail($email);
    $this->validatePassword($password, true);
    $roles = $this->normalizeRoles($roles);

    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    $hash = password_hash($password, $algo);
    if ($hash === false) throw new HttpException(500, 'HASH_ERROR', 'No se pudo generar hash');

    $repo = new AuthRepository($this->db);

    $this->db->beginTransaction();
    try {
      $newUserId = $repo->createUser([
        'username' => $username,
        'password_hash' => $hash,
        'full_name' => $fullName,
        'email' => $email,
        'active_from' => gmdate('Y-m-d H:i:s'),
        'inactive_at' => null,
      ], $ctx->userId);

      foreach ($roles as $r) {
        $repo->assignRoleToUserByCode($newUserId, $r, $ctx->userId);
      }

      $this->db->commit();

      $res->json(201, [
        'ok' => true,
        'data' => ['id' => $newUserId, 'username' => $username, 'fullName' => $fullName, 'roles' => $roles],
        'error' => null,
      ]);
    } catch (\PDOException $e) {
      $this->db->rollBack();
      $this->handlePdoException($e);
    } catch (\RuntimeException $e) {
      $this->db->rollBack();
      throw new HttpException(400, 'VALIDATION', $e->getMessage());
    } catch (\Throwable $e) {
      $this->db->rollBack();
      throw new HttpException(500, 'SERVER_ERROR', 'Error interno', $e);
    }
  }

  public function patchUser(AuthContext $ctx, int $id, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $repo = new AuthRepository($this->db);
    $existing = $repo->findUserById($id);
    if (!$existing) throw new HttpException(404, 'NOT_FOUND', 'Usuario no existe');

    $fields = [];
    if (array_key_exists('username', $body)) {
      $username = trim((string)$body['username']);
      $this->validateUsername($username);
      $fields['username'] = $username;
    }
    if (array_key_exists('fullName', $body)) {
      $fullName = trim((string)$body['fullName']);
      $this->validateFullName($fullName);
      $fields['full_name'] = $fullName;
    }
    if (array_key_exists('email', $body)) {
      $email = $body['email'] === null ? null : trim((string)$body['email']);
      $this->validateEmail($email);
      $fields['email'] = $email === '' ? null : $email;
    }
    if (array_key_exists('password', $body)) {
      $password = (string)$body['password'];
      if ($password !== '') {
        $this->validatePassword($password, false);
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $hash = password_hash($password, $algo);
        if ($hash === false) throw new HttpException(500, 'HASH_ERROR', 'No se pudo generar hash');
        $fields['password_hash'] = $hash;
      }
    }

    $roles = null;
    if (array_key_exists('roles', $body)) {
      $roles = $this->normalizeRoles($body['roles']);
    }

    if (count($fields) === 0 && $roles === null) {
      throw new HttpException(400, 'VALIDATION', 'No hay campos para actualizar');
    }

    $this->db->beginTransaction();
    try {
      if (count($fields) > 0) {
        $repo->patchUser($id, $fields, $ctx->userId);
      }
      if ($roles !== null) {
        $repo->deactivateActiveUserRoles($id, $ctx->userId);
        foreach ($roles as $r) {
          $repo->assignRoleToUserByCode($id, $r, $ctx->userId);
        }
      }

      $this->db->commit();
      $res->json(200, ['ok' => true, 'data' => ['id' => $id], 'error' => null]);
    } catch (\PDOException $e) {
      $this->db->rollBack();
      $this->handlePdoException($e);
    } catch (\RuntimeException $e) {
      $this->db->rollBack();
      throw new HttpException(400, 'VALIDATION', $e->getMessage());
    } catch (\Throwable $e) {
      $this->db->rollBack();
      throw new HttpException(500, 'SERVER_ERROR', 'Error interno', $e);
    }
  }

  public function deleteUser(AuthContext $ctx, int $id, Response $res): void
  {
    if ($ctx->userId === $id) {
      throw new HttpException(400, 'VALIDATION', 'No puedes eliminar tu propio usuario');
    }

    $repo = new AuthRepository($this->db);
    $existing = $repo->findUserById($id);
    if (!$existing) throw new HttpException(404, 'NOT_FOUND', 'Usuario no existe');
    if ($existing['inactive_at'] !== null) {
      throw new HttpException(409, 'ALREADY_INACTIVE', 'Usuario ya está inactivo');
    }

    $this->db->beginTransaction();
    try {
      $repo->deactivateActiveUserRoles($id, $ctx->userId);
      $repo->deactivateUser($id, $ctx->userId);
      $this->db->commit();
      $res->json(200, ['ok' => true, 'data' => ['id' => $id], 'error' => null]);
    } catch (\Throwable $e) {
      $this->db->rollBack();
      throw new HttpException(500, 'SERVER_ERROR', 'Error interno', $e);
    }
  }

  private function normalizeRoles($roles): array
  {
    if (!is_array($roles) || count($roles) === 0) {
      throw new HttpException(400, 'VALIDATION', 'Debes seleccionar al menos un rol');
    }

    $out = [];
    foreach ($roles as $r) {
      $code = trim((string)$r);
      if ($code !== '') $out[] = $code;
    }
    $out = array_values(array_unique($out));
    if (count($out) === 0) {
      throw new HttpException(400, 'VALIDATION', 'Debes seleccionar al menos un rol');
    }
    return $out;
  }

  private function validateUsername(string $username): void
  {
    if ($username === '') throw new HttpException(400, 'VALIDATION', 'username es requerido');
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
      throw new HttpException(400, 'VALIDATION', 'Username inválido. Usa 3-50 caracteres: letras, números, punto, guion y guion bajo');
    }
  }

  private function validateFullName(string $fullName): void
  {
    if ($fullName === '') throw new HttpException(400, 'VALIDATION', 'fullName es requerido');
    if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 120) {
      throw new HttpException(400, 'VALIDATION', 'Nombre completo inválido. Mínimo 3 y máximo 120 caracteres');
    }
  }

  private function validateEmail(?string $email): void
  {
    if ($email === null || $email === '') return;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new HttpException(400, 'VALIDATION', 'Email inválido');
    }
    if (mb_strlen($email) > 150) {
      throw new HttpException(400, 'VALIDATION', 'Email demasiado largo');
    }
  }

  private function validatePassword(string $password, bool $required): void
  {
    if ($password === '') {
      if ($required) throw new HttpException(400, 'VALIDATION', 'password es requerido');
      return;
    }

    if (strlen($password) < 8) {
      throw new HttpException(400, 'VALIDATION', 'Password muy corto (mínimo 8)');
    }
    if (!preg_match('/^[A-Za-z0-9.\-_\*]+$/', $password)) {
      throw new HttpException(400, 'VALIDATION', 'Password inválido. Solo se permiten letras, números, punto, guion medio, guion bajo y asterisco');
    }
    if (!preg_match('/[A-Z]/', $password)) {
      throw new HttpException(400, 'VALIDATION', 'Password inválido. Debe incluir al menos una letra mayúscula');
    }
    if (!preg_match('/\d/', $password)) {
      throw new HttpException(400, 'VALIDATION', 'Password inválido. Debe incluir al menos un número');
    }
    if (!preg_match('/[.\-_\*]/', $password)) {
      throw new HttpException(400, 'VALIDATION', 'Password inválido. Debe incluir al menos un carácter especial permitido');
    }
  }

  private function toBool($v): bool
  {
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','true','yes','y','on'], true);
  }

  private function handlePdoException(\PDOException $e): void
  {
    $sqlState = (string)($e->errorInfo[0] ?? '');
    $driverCode = (int)($e->errorInfo[1] ?? 0);
    if ($sqlState === '23000' && $driverCode === 1062) {
      throw new HttpException(409, 'USERNAME_EXISTS', 'username ya existe');
    }
    throw new HttpException(500, 'DB_ERROR', 'Error de base de datos', $e);
  }
}
