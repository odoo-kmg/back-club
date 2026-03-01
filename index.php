<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use App\Config;
use App\Db;
use App\Http\Request;
use App\Http\Response;
use App\Http\HttpException;
use App\Security\Auth;
use App\Security\Cors;
use App\Security\Headers;
use App\Controllers\AuthController;
use App\Controllers\AdminUserController;

$config = Config::load(__DIR__ . '/config');
$db = Db::pdo($config);

Headers::applySecurityHeaders();
Cors::handle($config);

$req = Request::fromGlobals($config);
$res = new Response();

$method = $req->method();
$path   = $req->path(); // sin /api (base_path)

$authController = new AuthController($config, $db);
$adminUserController = new AdminUserController($config, $db);

try {
  // Health check
  if ($method === 'GET' && $path === '/v1/ping') {
    $res->json(200, ['ok' => true, 'data' => ['pong' => true, 'ts' => gmdate('c')], 'error' => null]);
    exit;
  }

  // Auth
  if ($method === 'POST' && $path === '/v1/auth/login') {
    $authController->login($req, $res);
    exit;
  }

  if ($method === 'GET' && $path === '/v1/auth/me') {
    $ctx = Auth::requireAuth($config, $db, $req);
    $authController->me($ctx, $res);
    exit;
  }

  // Admin - Users
  if ($method === 'POST' && $path === '/v1/admin/users') {
    $ctx = Auth::requireAuth($config, $db, $req);
    Auth::requirePermission($ctx, 'SEC_USER_WRITE');
    $adminUserController->createUser($ctx, $req, $res);
    exit;
  }

  $res->json(404, [
    'ok' => false,
    'data' => null,
    'error' => ['code' => 'NOT_FOUND', 'message' => 'Ruta no encontrada'],
  ]);
} catch (HttpException $e) {
  $res->json($e->status, [
    'ok' => false,
    'data' => null,
    'error' => ['code' => $e->code, 'message' => $e->getMessage()],
  ]);
} catch (Throwable $e) {
  $debug = (bool)($config['app']['debug'] ?? false);
  $res->json(500, [
    'ok' => false,
    'data' => null,
    'error' => [
      'code' => 'SERVER_ERROR',
      'message' => $debug ? $e->getMessage() : 'Error interno',
    ],
  ]);
}