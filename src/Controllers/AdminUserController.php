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

  public function createUser(AuthContext $ctx, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $fullName = trim((string)($body['fullName'] ?? ''));
    $email = isset($body['email']) ? trim((string)$body['email']) : null;
    $roles = $body['roles'] ?? [];

    if ($username === '' || $password === '' || $fullName === '') {
      throw new HttpException(400, 'VALIDATION', 'username, password, fullName son requeridos');
    }

    // Política mínima: 10+ chars recomendado (ajústalo)
    if (strlen($password) < 10) {
      throw new HttpException(400, 'VALIDATION', 'Password muy corto (mínimo 10)');
    }

    if (!is_array($roles) || count($roles) === 0) {
      $roles = ['REGISTRATION_OPERATOR'];
    }

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
        $code = trim((string)$r);
        if ($code !== '') $repo->assignRoleToUserByCode($newUserId, $code, $ctx->userId);
      }

      $this->db->commit();

      $res->json(201, [
        'ok' => true,
        'data' => ['id' => $newUserId, 'username' => $username, 'fullName' => $fullName, 'roles' => $roles],
        'error' => null,
      ]);
    } catch (\Throwable $e) {
      $this->db->rollBack();
      throw new HttpException(400, 'CREATE_USER_FAILED', $e->getMessage());
    }
  }
}