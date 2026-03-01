<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Http\HttpException;
use App\Repositories\AuthRepository;
use App\Security\Jwt;
use App\Security\AuthContext;
use PDO;

final class AuthController
{
  private array $config;
  private PDO $db;

  public function __construct(array $config, PDO $db)
  {
    $this->config = $config;
    $this->db = $db;
  }

  public function login(Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');

    if ($username === '' || $password === '') {
      throw new HttpException(400, 'VALIDATION', 'username y password son requeridos');
    }

    $repo = new AuthRepository($this->db);
    $user = $repo->findActiveUserByUsername($username);

    // Respuesta genérica para no filtrar existencia
    if (!$user || !password_verify($password, $user['password_hash'])) {
      usleep(150000);
      throw new HttpException(401, 'INVALID_CREDENTIALS', 'Credenciales inválidas');
    }

    $userId = (int)$user['id'];
    $roles = $repo->getActiveRoleCodesForUser($userId);
    $perms = $repo->getActivePermissionCodesForUser($userId);

    $jwt = $this->config['jwt'];
    $now = time();
    $ttl = (int)$jwt['ttl_seconds'];

    $payload = [
      'iss' => (string)$jwt['issuer'],
      'aud' => (string)$jwt['audience'],
      'iat' => $now,
      'nbf' => $now,
      'exp' => $now + $ttl,
      'sub' => $userId,
    ];

    $token = Jwt::encode($payload, (string)$jwt['secret']);

    $res->json(200, [
      'ok' => true,
      'data' => [
        'token' => $token,
        'user' => ['id' => $userId, 'username' => $user['username'], 'fullName' => $user['full_name']],
        'roles' => $roles,
        'permissions' => $perms,
      ],
      'error' => null,
    ]);
  }

  public function me(AuthContext $ctx, Response $res): void
  {
    $res->json(200, [
      'ok' => true,
      'data' => [
        'user' => ['id' => $ctx->userId, 'username' => $ctx->username, 'fullName' => $ctx->fullName],
        'roles' => $ctx->roles,
        'permissions' => $ctx->permissions,
      ],
      'error' => null,
    ]);
  }
}