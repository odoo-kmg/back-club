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
use App\Controllers\EventController;
use App\Controllers\ResourceTypeController;
use App\Controllers\ParticipationScopeController;

$config = Config::load(__DIR__ . '/config');
$db = Db::pdo($config);

Headers::applySecurityHeaders();
Cors::handle($config);

$req = Request::fromGlobals($config);
$res = new Response();

$method = $req->method();
$path   = $req->path();

$authController = new AuthController($config, $db);
$adminUserController = new AdminUserController($config, $db);

// Fase 1 (catálogos)
$eventController = new EventController($db);
$resourceTypeController = new ResourceTypeController($db);
$scopeController = new ParticipationScopeController($db);

try {
  // Small route table: [METHOD, REGEX, HANDLER]
  $routes = [

    // Health
    ['GET', '#^/v1/ping$#', function () use ($res) {
      $res->json(200, ['ok' => true, 'data' => ['pong' => true, 'ts' => gmdate('c')], 'error' => null]);
    }],

    // Auth
    ['POST', '#^/v1/auth/login$#', function () use ($authController, $req, $res) {
      $authController->login($req, $res);
    }],
    ['GET', '#^/v1/auth/me$#', function () use ($authController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      $authController->me($ctx, $res);
    }],

    // Admin - Users
    ['POST', '#^/v1/admin/users$#', function () use ($adminUserController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'SEC_USER_WRITE');
      $adminUserController->createUser($ctx, $req, $res);
    }],

    // ==========================
    // FASE 1 - CATÁLOGOS
    // ==========================

    // Events
    ['GET',  '#^/v1/events$#', function () use ($eventController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_EVENT_READ');
      $eventController->list($req, $res);
    }],
    ['POST', '#^/v1/events$#', function () use ($eventController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_EVENT_WRITE');
      $eventController->create($ctx, $req, $res);
    }],
    ['PATCH', '#^/v1/events/(?P<id>\d+)$#', function ($m) use ($eventController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_EVENT_WRITE');
      $eventController->patch($ctx, (int)$m['id'], $req, $res);
    }],

    // Resource types
    ['GET', '#^/v1/resource-types$#', function () use ($resourceTypeController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_RESOURCE_READ');
      $resourceTypeController->list($req, $res);
    }],
    ['POST', '#^/v1/resource-types$#', function () use ($resourceTypeController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_RESOURCE_WRITE');
      $resourceTypeController->create($ctx, $req, $res);
    }],
    ['PATCH', '#^/v1/resource-types/(?P<id>\d+)$#', function ($m) use ($resourceTypeController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_RESOURCE_WRITE');
      $resourceTypeController->patch($ctx, (int)$m['id'], $req, $res);
    }],

    // Participation scopes
    ['GET', '#^/v1/participation-scopes$#', function () use ($scopeController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_SCOPE_READ');
      $scopeController->list($req, $res);
    }],
    ['POST', '#^/v1/participation-scopes$#', function () use ($scopeController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_SCOPE_WRITE');
      $scopeController->create($ctx, $req, $res);
    }],
    ['PATCH', '#^/v1/participation-scopes/(?P<id>\d+)$#', function ($m) use ($scopeController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_SCOPE_WRITE');
      $scopeController->patch($ctx, (int)$m['id'], $req, $res);
    }],
  ];

  foreach ($routes as [$mtd, $rx, $handler]) {
    if ($method !== $mtd) continue;
    if (preg_match($rx, $path, $matches)) {
      // pass named matches only
      $named = array_filter($matches, fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY);
      $handler($named);
      exit;
    }
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
