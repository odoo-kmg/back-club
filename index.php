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
use App\Controllers\DrawController;
use App\Controllers\EligibilityRangeController;
use App\Controllers\ExclusionController;

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

// Fase 2 (sorteos)
$drawController = new DrawController($db);
$eligibilityRangeController = new EligibilityRangeController($db);
$exclusionController = new ExclusionController($db);

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

    // ==========================
    // FASE 2 - DRAWS (SORTEOS)
    // ==========================

    // Draws
    ['GET', '#^/v1/draws$#', function () use ($drawController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);

      // If forRegistration=true we allow registrars; otherwise config read.
      $forRegistration = strtolower((string)$req->query('forRegistration', 'false'));
      $isForReg = in_array($forRegistration, ['1','true','yes','y','on'], true);

      if ($isForReg) {
        if (!$ctx->hasPermission('OPS_PARTICIPANT_REGISTER') && !$ctx->hasPermission('CFG_DRAW_READ')) {
          throw new HttpException(403, 'FORBIDDEN', 'Sin permisos');
        }
      } else {
        Auth::requirePermission($ctx, 'CFG_DRAW_READ');
      }

      $drawController->list($req, $res);
    }],
    ['POST', '#^/v1/draws$#', function () use ($drawController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_DRAW_WRITE');
      $drawController->create($ctx, $req, $res);
    }],
    ['GET', '#^/v1/draws/(?P<id>\d+)$#', function ($m) use ($drawController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_DRAW_READ');
      $drawController->get((int)$m['id'], $res);
    }],
    ['PATCH', '#^/v1/draws/(?P<id>\d+)$#', function ($m) use ($drawController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_DRAW_WRITE');
      $drawController->patch($ctx, (int)$m['id'], $req, $res);
    }],

    // Open/Close registration
    ['POST', '#^/v1/draws/(?P<id>\d+)/open-registration$#', function ($m) use ($drawController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_DRAW_OPEN_REG');
      $drawController->openRegistration($ctx, (int)$m['id'], $req, $res);
    }],
    ['POST', '#^/v1/draws/(?P<id>\d+)/close-registration$#', function ($m) use ($drawController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_DRAW_CLOSE_REG');
      $drawController->closeRegistration($ctx, (int)$m['id'], $res);
    }],

    // Eligibility ranges
    ['GET', '#^/v1/draws/(?P<id>\d+)/eligibility-ranges$#', function ($m) use ($drawController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_DRAW_READ');
      $drawController->listEligibilityRanges((int)$m['id'], $req, $res);
    }],
    ['POST', '#^/v1/draws/(?P<id>\d+)/eligibility-ranges$#', function ($m) use ($drawController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_DRAW_WRITE');
      $drawController->createEligibilityRange($ctx, (int)$m['id'], $req, $res);
    }],
    ['PATCH', '#^/v1/eligibility-ranges/(?P<id>\d+)$#', function ($m) use ($eligibilityRangeController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_DRAW_WRITE');
      $eligibilityRangeController->patch($ctx, (int)$m['id'], $req, $res);
    }],

    // Exclusions
    ['GET', '#^/v1/draws/(?P<id>\d+)/exclusions$#', function ($m) use ($drawController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_DRAW_EXCLUSION_READ');
      $drawController->listExclusions((int)$m['id'], $req, $res);
    }],
    ['POST', '#^/v1/draws/(?P<id>\d+)/exclusions$#', function ($m) use ($drawController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_DRAW_EXCLUSION_WRITE');
      $drawController->createExclusion($ctx, (int)$m['id'], $req, $res);
    }],
    ['PATCH', '#^/v1/exclusions/(?P<id>\d+)$#', function ($m) use ($exclusionController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_DRAW_EXCLUSION_WRITE');
      $exclusionController->patch($ctx, (int)$m['id'], $req, $res);
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
    'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()],
  ]);
} catch (Throwable $e) {
  $debug = (bool)($config['app']['debug'] ?? false);

  // Default
  $status = 500;
  $code = 'SERVER_ERROR';
  $message = $debug ? $e->getMessage() : 'Error interno';

  // Map common error types to predictable HTTP responses.
  if ($e instanceof \PDOException) {
    $sqlState = (string)($e->errorInfo[0] ?? '');
    $driverCode = (int)($e->errorInfo[1] ?? 0);

    // MySQL common driver codes:
    // 1062: Duplicate entry
    // 1452: Cannot add or update a child row: a foreign key constraint fails
    // 1048: Column cannot be null
    if ($sqlState === '23000' && $driverCode === 1062) {
      $status = 409;
      $code = 'DUPLICATE';
      $message = $debug ? $e->getMessage() : 'Conflicto: registro duplicado';
    } elseif ($sqlState === '23000' && $driverCode === 1452) {
      $status = 409;
      $code = 'FK_CONSTRAINT';
      $message = $debug ? $e->getMessage() : 'Conflicto: referencia inválida';
    } elseif ($driverCode === 1048) {
      $status = 400;
      $code = 'VALIDATION';
      $message = $debug ? $e->getMessage() : 'Validación: falta un campo requerido';
    } else {
      $status = 500;
      $code = 'DB_ERROR';
      $message = $debug ? $e->getMessage() : 'Error de base de datos';
    }
  } elseif ($e instanceof \InvalidArgumentException) {
    $status = 400;
    $code = 'VALIDATION';
    $message = $debug ? $e->getMessage() : 'Parámetros inválidos';
  } elseif ($e instanceof \DomainException) {
    $status = 422;
    $code = 'BUSINESS_RULE';
    $message = $debug ? $e->getMessage() : 'Regla de negocio no cumplida';
  }

  $res->json($status, [
    'ok' => false,
    'data' => null,
    'error' => [
      'code' => $code,
      'message' => $message,
    ],
  ]);
}
