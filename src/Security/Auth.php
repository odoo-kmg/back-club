<?php
declare(strict_types=1);

namespace App\Security;

use App\Http\Request;
use App\Http\HttpException;
use App\Repositories\AuthRepository;
use PDO;

final class Auth
{
  public static function requireAuth(array $config, PDO $db, Request $req): AuthContext
  {
    $auth = $req->header('authorization');
    if (!$auth || !preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
      throw new HttpException(401, 'UNAUTHORIZED', 'Falta Authorization Bearer');
    }

    $token = trim($m[1]);
    try {
      $payload = Jwt::decodeAndVerify($token, (string)$config['jwt']['secret']);
    } catch (\Throwable $e) {
      throw new HttpException(401, 'UNAUTHORIZED', 'Token inválido');
    }

    $now = time();
    if (isset($payload['exp']) && $now >= (int)$payload['exp']) {
      throw new HttpException(401, 'TOKEN_EXPIRED', 'Token expirado');
    }

    $userId = (int)($payload['sub'] ?? 0);
    if ($userId <= 0) throw new HttpException(401, 'UNAUTHORIZED', 'Token inválido');

    $repo = new AuthRepository($db);
    $user = $repo->findActiveUserById($userId);
    if (!$user) throw new HttpException(401, 'UNAUTHORIZED', 'Usuario inactivo o no existe');

    $roles = $repo->getActiveRoleCodesForUser($userId);
    $perms = $repo->getActivePermissionCodesForUser($userId);

    return new AuthContext($userId, $user['username'], $user['full_name'], $roles, $perms);
  }

  public static function requirePermission(AuthContext $ctx, string $perm): void
  {
    if (!$ctx->hasPermission($perm)) {
      throw new HttpException(403, 'FORBIDDEN', 'Sin permisos');
    }
  }
}