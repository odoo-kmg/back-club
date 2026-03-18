<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use App\Config;
use App\Db;
use App\Http\Request;
use App\Http\Response;
use App\Http\HttpException;
use App\Security\Auth;
use App\Security\ApiKey;
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
use App\Controllers\ParticipantController;
use App\Controllers\ActionBlockController;
use App\Controllers\ImportController;
use App\Controllers\ShareholderController;

// Fase 4
use App\Controllers\ExecutionController;
use App\Controllers\WinnerController;
use App\Controllers\ExportController;
use App\Controllers\BotmakerController;
use App\Controllers\DrawNotificationController;
date_default_timezone_set('America/Caracas');
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

// Fase 1
$eventController = new EventController($db);
$resourceTypeController = new ResourceTypeController($db);
$scopeController = new ParticipationScopeController($db);

// Fase 2
$drawController = new DrawController($db);
$eligibilityRangeController = new EligibilityRangeController($db);
$exclusionController = new ExclusionController($db);

// Fase 3
$participantController = new ParticipantController($db);
$actionBlockController = new ActionBlockController($db);
$importController = new ImportController($db);
$shareholderController = new ShareholderController($db);

// Fase 4
$executionController = new ExecutionController($db, $config);
$winnerController = new WinnerController($db);
$exportController = new ExportController($db);
$botmakerController = new BotmakerController($db);
$drawNotificationController = new DrawNotificationController($db, $config);

// Public landing: service user resolution for audit fields.
// Priority:
//  1) PUBLIC_WEB_SERVICE_USER_ID (numeric)
//  2) PUBLIC_WEB_SERVICE_USERNAME (default: svc_web_registration) lookup in app_user
function resolveServiceUser(PDO $db, string $idEnv, string $usernameEnv, string $defaultUsername): array
{
  $id = (int)($_ENV[$idEnv] ?? getenv($idEnv) ?? 0);
  $username = (string)($_ENV[$usernameEnv] ?? getenv($usernameEnv) ?? $defaultUsername);
  $username = trim($username) !== '' ? trim($username) : $defaultUsername;

  if ($id > 0) return [$id, $username];

  $st = $db->prepare("SELECT id, username FROM app_user WHERE username = ? AND (inactive_at IS NULL OR inactive_at > NOW()) LIMIT 1");
  $st->execute([$username]);
  $row = $st->fetch();
  if ($row) return [(int)$row['id'], (string)$row['username']];

  return [0, $username];
}

function resolvePublicServiceUser(PDO $db): array
{
  return resolveServiceUser($db, 'PUBLIC_WEB_SERVICE_USER_ID', 'PUBLIC_WEB_SERVICE_USERNAME', 'svc_web_registration');
}

function resolveBotmakerServiceUser(PDO $db): array
{
  return resolveServiceUser($db, 'BOTMAKER_SERVICE_USER_ID', 'BOTMAKER_SERVICE_USERNAME', 'svc_web_registration');
}

try {
  $routes = [

    // Health
    ['GET', '#^/v1/ping$#', function () use ($res) {
      $res->json(200, ['ok' => true, 'data' => ['pong' => true, 'ts' => date('c')], 'error' => null]);
    }],

    // ==========================
    // PUBLIC - Landing (no auth)
    // ==========================

    // List draws available for registration (server-side filter)
    ['GET', '#^/v1/public/draws/for-registration$#', function () use ($db, $res) {
      $now = date('Y-m-d H:i:s');
      $sql = "SELECT id, event_id, resource_type_id, participation_scope_id, name, resource_context,
                     reg_open_at, reg_close_at, winners_count, pick_interval_seconds,
                     use_start_date, use_end_date, status, active_from, inactive_at
              FROM `draw`
              WHERE status = 'REG_OPEN'
                AND active_from <= ? AND (inactive_at IS NULL OR inactive_at > ?)
                AND reg_open_at <= ? AND reg_close_at > ?
              ORDER BY id DESC";
      $st = $db->prepare($sql);
      $st->execute([$now, $now, $now, $now]);
      $rows = $st->fetchAll();

      $items = array_map(function ($r) {
        return [
          'id' => (int)$r['id'],
          'eventId' => (int)$r['event_id'],
          'resourceTypeId' => (int)$r['resource_type_id'],
          'participationScopeId' => (int)$r['participation_scope_id'],
          'name' => $r['name'],
          'resourceContext' => $r['resource_context'],
          'regOpenAt' => $r['reg_open_at'],
          'regCloseAt' => $r['reg_close_at'],
          'winnersCount' => (int)$r['winners_count'],
          'pickIntervalSeconds' => (int)$r['pick_interval_seconds'],
          'useStartDate' => $r['use_start_date'],
          'useEndDate' => $r['use_end_date'],
          'status' => $r['status'],
          'activeFrom' => $r['active_from'],
          'inactiveAt' => $r['inactive_at'],
        ];
      }, $rows);

      $res->json(200, ['ok' => true, 'data' => $items, 'error' => null]);
    }],

    // Register participant via public landing (channel forced to WEB)
    ['POST', '#^/v1/public/draws/(?P<id>[0-9]+)/participants$#', function ($m) use ($participantController, $db, $req, $res) {
      [$serviceUserId, $serviceUsername] = resolvePublicServiceUser($db);
      $participantController->registerPublic((int)$m['id'], $serviceUserId, $serviceUsername, $req, $res);
    }],

    // ==========================
    // BOTMAKER - Machine-to-machine
    // ==========================

    ['GET', '#^/v1/integrations/botmaker/draws/for-registration$#', function () use ($botmakerController, $config, $req, $res) {
      ApiKey::requireKey($config, $req, 'botmaker');
      $botmakerController->listDrawsForRegistration($res);
    }],

    ['POST', '#^/v1/integrations/botmaker/draws/(?P<id>[0-9]+)/participants$#', function ($m) use ($botmakerController, $config, $db, $req, $res) {
      ApiKey::requireKey($config, $req, 'botmaker');
      [$serviceUserId, $serviceUsername] = resolveBotmakerServiceUser($db);
      $botmakerController->registerParticipant((int)$m['id'], $serviceUserId, $serviceUsername, $req, $res);
    }],

    ['GET', '#^/v1/integrations/botmaker/draws/for-results$#', function () use ($botmakerController, $config, $req, $res) {
      ApiKey::requireKey($config, $req, 'botmaker');
      $botmakerController->listDrawsForResults($req, $res);
    }],

    ['GET', '#^/v1/integrations/botmaker/draws/(?P<id>[0-9]+)/results/by-action$#', function ($m) use ($botmakerController, $config, $req, $res) {
      ApiKey::requireKey($config, $req, 'botmaker');
      $botmakerController->getResultByAction((int)$m['id'], $req, $res);
    }],

    // Auth
    ['POST', '#^/v1/auth/login$#', function () use ($authController, $req, $res) {
      $authController->login($req, $res);
    }],
    ['GET', '#^/v1/auth/me$#', function () use ($authController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      $authController->me($ctx, $res);
    }],

    // Admin - Users / Roles
    ['GET', '#^/v1/admin/users$#', function () use ($adminUserController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'SEC_USER_WRITE');
      $adminUserController->listUsers($ctx, $req, $res);
    }],
    ['POST', '#^/v1/admin/users$#', function () use ($adminUserController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'SEC_USER_WRITE');
      $adminUserController->createUser($ctx, $req, $res);
    }],
    ['PATCH', '#^/v1/admin/users/(?P<id>\d+)$#', function ($m) use ($adminUserController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'SEC_USER_WRITE');
      $adminUserController->patchUser($ctx, (int)$m['id'], $req, $res);
    }],
    ['DELETE', '#^/v1/admin/users/(?P<id>\d+)$#', function ($m) use ($adminUserController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'SEC_USER_WRITE');
      $adminUserController->deleteUser($ctx, (int)$m['id'], $res);
    }],
    ['GET', '#^/v1/admin/roles$#', function () use ($adminUserController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'SEC_USER_WRITE');
      $adminUserController->listRoles($ctx, $req, $res);
    }],

    // ==========================
    // FASE 1 - CATÁLOGOS
    // ==========================

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

    // Shareholders
    ['GET', '#^/v1/shareholders$#', function () use ($shareholderController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_RESOURCE_READ');
      $shareholderController->list($req, $res);
    }],
    ['POST', '#^/v1/shareholders$#', function () use ($shareholderController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_RESOURCE_WRITE');
      $shareholderController->create($ctx, $req, $res);
    }],
    ['PATCH', '#^/v1/shareholders/(?P<id>\d+)$#', function ($m) use ($shareholderController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_RESOURCE_WRITE');
      $shareholderController->patch($ctx, (int)$m['id'], $req, $res);
    }],
    ['DELETE', '#^/v1/shareholders/(?P<id>\d+)$#', function ($m) use ($shareholderController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_RESOURCE_WRITE');
      $shareholderController->delete($ctx, (int)$m['id'], $res);
    }],
    ['POST', '#^/v1/shareholders/imports$#', function () use ($shareholderController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_RESOURCE_WRITE');
      $shareholderController->importCsv($ctx, $req, $res);
    }],
    ['GET', '#^/v1/shareholders/imports/(?P<id>\d+)$#', function ($m) use ($shareholderController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_RESOURCE_READ');
      $shareholderController->getImport((int)$m['id'], $res);
    }],
    ['GET', '#^/v1/shareholders/imports/(?P<id>\d+)/rows$#', function ($m) use ($shareholderController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'CFG_RESOURCE_READ');
      $shareholderController->listImportRows((int)$m['id'], $req, $res);
    }],

    // ==========================
    // FASE 2 - DRAWS
    // ==========================

    ['GET', '#^/v1/draws$#', function () use ($drawController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);

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

    // ==========================
    // FASE 3 - OPERACIÓN
    // ==========================

    ['GET', '#^/v1/draws/(?P<id>\d+)/participants$#', function ($m) use ($participantController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'OPS_PARTICIPANT_READ');
      $participantController->listByDraw((int)$m['id'], $req, $res);
    }],
    ['POST', '#^/v1/draws/(?P<id>\d+)/participants$#', function ($m) use ($participantController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'OPS_PARTICIPANT_REGISTER');
      $participantController->register((int)$m['id'], $ctx, $req, $res);
    }],
    ['POST', '#^/v1/participants/(?P<id>\d+)/cancel$#', function ($m) use ($participantController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'OPS_PARTICIPANT_CANCEL');
      $participantController->cancel((int)$m['id'], $ctx, $req, $res);
    }],

    ['GET', '#^/v1/action-blocks$#', function () use ($actionBlockController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'OPS_BLOCK_READ');
      $actionBlockController->list($req, $res);
    }],
    ['POST', '#^/v1/action-blocks$#', function () use ($actionBlockController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'OPS_BLOCK_WRITE');
      $actionBlockController->create($ctx, $req, $res);
    }],
    ['PATCH', '#^/v1/action-blocks/(?P<id>\d+)$#', function ($m) use ($actionBlockController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'OPS_BLOCK_WRITE');
      $actionBlockController->patch((int)$m['id'], $ctx, $req, $res);
    }],
    ['POST', '#^/v1/action-blocks/(?P<id>\d+)/unblock$#', function ($m) use ($actionBlockController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'OPS_BLOCK_WRITE');
      $actionBlockController->unblock((int)$m['id'], $ctx, $req, $res);
    }],

    ['POST', '#^/v1/imports/action-blocks$#', function () use ($importController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'OPS_BLOCK_IMPORT');
      $importController->importActionBlocks($ctx, $req, $res);
    }],
    ['GET', '#^/v1/imports/(?P<id>\d+)$#', function ($m) use ($importController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'OPS_BLOCK_READ');
      $importController->getImport((int)$m['id'], $req, $res);
    }],
    ['GET', '#^/v1/imports/(?P<id>\d+)/rows$#', function ($m) use ($importController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'OPS_BLOCK_READ');
      $importController->listImportRows((int)$m['id'], $req, $res);
    }],

    // ==========================
    // FASE 4 - EJECUCIÓN + GANADORES + EXPORT
    // ==========================

    ['POST', '#^/v1/draws/(?P<id>\d+)/executions/start$#', function ($m) use ($executionController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'OPS_DRAW_EXECUTE_START');
      $executionController->start((int)$m['id'], $ctx, $req, $res);
    }],

    ['POST', '#^/v1/draws/(?P<id>\d+)/executions/(?P<eid>\d+)/pick-next$#', function ($m) use ($executionController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'OPS_DRAW_EXECUTE_START');
      $executionController->pickNext((int)$m['id'], (int)$m['eid'], $ctx, $req, $res);
    }],

    ['POST', '#^/v1/draws/(?P<id>\d+)/executions/(?P<eid>\d+)/finish$#', function ($m) use ($executionController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'OPS_DRAW_EXECUTE_FINISH');
      $executionController->finish((int)$m['id'], (int)$m['eid'], $ctx, $res);
    }],

    ['POST', '#^/v1/draws/(?P<id>\d+)/executions/resume$#', function ($m) use ($executionController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'OPS_DRAW_EXECUTE_RESUME');
      $executionController->resume((int)$m['id'], $ctx, $req, $res);
    }],

    ['POST', '#^/v1/draws/(?P<id>\d+)/executions/restart$#', function ($m) use ($executionController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'OPS_DRAW_EXECUTE_RESTART');
      $executionController->restart((int)$m['id'], $ctx, $req, $res);
    }],

    ['GET', '#^/v1/draws/(?P<id>\d+)/winners$#', function ($m) use ($winnerController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'RPT_EXPORT_READ');
      $winnerController->list((int)$m['id'], $ctx, $req, $res);
    }],

    ['GET', '#^/v1/draws/(?P<id>\d+)/export$#', function ($m) use ($exportController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'RPT_EXPORT_READ');
      $exportController->exportJson((int)$m['id'], $req, $res);
    }],

    ['GET', '#^/v1/draws/(?P<id>\d+)/export/results-notification$#', function ($m) use ($botmakerController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'RPT_EXPORT_READ');
      $botmakerController->exportResultsNotification((int)$m['id'], $req, $res);
    }],

    ['POST', '#^/v1/draws/(?P<id>\d+)/notifications/results$#', function ($m) use ($drawNotificationController, $config, $db, $req, $res) {
      $ctx = Auth::requireAuth($config, $db, $req);
      Auth::requirePermission($ctx, 'OPS_DRAW_EXECUTE_FINISH');
      $drawNotificationController->notifyResults((int)$m['id'], $ctx, $res);
    }],
  ];

  foreach ($routes as [$mtd, $rx, $handler]) {
    if ($method !== $mtd) continue;
    if (preg_match($rx, $path, $matches)) {
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

  $status = 500;
  $code = 'SERVER_ERROR';
  $message = $debug ? $e->getMessage() : 'Error interno';

  if ($e instanceof \PDOException) {
    $sqlState = (string)($e->errorInfo[0] ?? '');
    $driverCode = (int)($e->errorInfo[1] ?? 0);

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
