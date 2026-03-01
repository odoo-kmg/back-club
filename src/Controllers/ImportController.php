<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Http\HttpException;
use App\Repositories\ImportRepository;
use App\Repositories\ActionBlockRepository;
use App\Security\AuthContext;
use PDO;

final class ImportController
{
  private PDO $db;
  public function __construct(PDO $db) { $this->db = $db; }

  public function importActionBlocks(AuthContext $ctx, Request $req, Response $res): void
  {
    // multipart/form-data
    $file = $req->file('file');
    if (!$file || (int)($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
      throw new HttpException(400, 'FILE_REQUIRED', 'Archivo requerido (field: file)');
    }

    $name = (string)($file['name'] ?? 'import.csv');
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
      throw new HttpException(400, 'FILE_REQUIRED', 'No se pudo leer el archivo');
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
      throw new HttpException(422, 'UNSUPPORTED_FILE', 'Por simplicidad, en esta fase solo soportamos CSV');
    }

    $defaultScopeType = strtoupper(trim((string)$req->post('defaultScopeType', 'GLOBAL')));
    if (!in_array($defaultScopeType, ['GLOBAL','DRAW'], true)) {
      throw new HttpException(400, 'VALIDATION', 'defaultScopeType inválido (GLOBAL|DRAW)');
    }
    $defaultScopeDrawId = null;
    $rawDraw = $req->post('scopeDrawId', null);
    if ($defaultScopeType === 'DRAW') {
      if ($rawDraw === null || $rawDraw === '' || !ctype_digit((string)$rawDraw) || (int)$rawDraw <= 0) {
        throw new HttpException(400, 'VALIDATION', 'scopeDrawId requerido cuando defaultScopeType=DRAW');
      }
      $defaultScopeDrawId = (int)$rawDraw;
    }

    $defaultOperation = strtoupper(trim((string)$req->post('defaultOperation', 'BLOCK')));
    if (!in_array($defaultOperation, ['BLOCK','UNBLOCK'], true)) {
      throw new HttpException(400, 'VALIDATION', 'defaultOperation inválido (BLOCK|UNBLOCK)');
    }

    $importRepo = new ImportRepository($this->db);
    $importId = $importRepo->createImport([
      'import_type' => 'ACTION_BLOCK',
      'original_filename' => $name,
      'uploaded_at' => gmdate('Y-m-d H:i:s'),
      'uploaded_by_user_id' => $ctx->userId,
      'process_status' => 'PENDING',
      'notes' => null,
    ], $ctx->userId);

    $blockRepo = new ActionBlockRepository($this->db);

    $total = 0;
    $ok = 0;
    $err = 0;

    $handle = fopen($tmp, 'r');
    if (!$handle) {
      $importRepo->setImportResult($importId, 'FAILED', 0, 0, 1, 'No se pudo abrir el archivo', $ctx->userId);
      throw new HttpException(500, 'IMPORT_FAILED', 'No se pudo abrir el archivo');
    }

    $rowNumber = 0;
    $firstRow = true;

    while (($line = fgets($handle)) !== false) {
      $rowNumber++;
      $line = trim($line);
      if ($line === '') continue;

      // delimiter detection
      $delimiter = ((strpos($line, ';') !== false) && (strpos($line, ',') === false)) ? ';' : ',';
      $cols = str_getcsv($line, $delimiter);

      // Skip header if first column is not numeric
      if ($firstRow) {
        $firstRow = false;
        $c0 = trim((string)($cols[0] ?? ''));
        if ($c0 !== '' && !ctype_digit($c0)) {
          continue;
        }
      }

      $total++;

      // CSV columns:
      // 0 actionNumber (required)
      // 1 reason (required for BLOCK)
      // 2 scopeType (optional)
      // 3 scopeDrawId (optional)
      // 4 operation (optional)
      $actionRaw = trim((string)($cols[0] ?? ''));
      $reason = trim((string)($cols[1] ?? ''));

      $scopeType = strtoupper(trim((string)($cols[2] ?? ''))) ?: $defaultScopeType;
      $scopeDrawId = null;
      $scopeDrawRaw = trim((string)($cols[3] ?? ''));

      $operation = strtoupper(trim((string)($cols[4] ?? ''))) ?: $defaultOperation;

      $status = 'OK';
      $errorMessage = null;

      try {
        if ($actionRaw === '' || !ctype_digit($actionRaw)) {
          throw new HttpException(400, 'VALIDATION', 'actionNumber inválido');
        }
        $actionNumber = (int)$actionRaw;
        if ($actionNumber <= 0 || $actionNumber > 7000) {
          throw new HttpException(400, 'VALIDATION', 'actionNumber fuera de rango (1..7000)');
        }

        if (!in_array($scopeType, ['GLOBAL','DRAW'], true)) {
          throw new HttpException(400, 'VALIDATION', 'scopeType inválido');
        }

        if ($scopeType === 'DRAW') {
          $useRaw = $scopeDrawRaw !== '' ? $scopeDrawRaw : (string)($defaultScopeDrawId ?? '');
          if ($useRaw === '' || !ctype_digit($useRaw) || (int)$useRaw <= 0) {
            throw new HttpException(400, 'VALIDATION', 'scopeDrawId requerido para scope DRAW');
          }
          $scopeDrawId = (int)$useRaw;
        }

        if (!in_array($operation, ['BLOCK','UNBLOCK'], true)) {
          throw new HttpException(400, 'VALIDATION', 'operation inválido (BLOCK|UNBLOCK)');
        }

        if ($reason === '') {
          throw new HttpException(400, 'VALIDATION', 'reason requerido');
        }

        $now = gmdate('Y-m-d H:i:s');

        if ($operation === 'BLOCK') {
          $existing = $blockRepo->findActiveBlock($actionNumber, $scopeType, $scopeDrawId);
          if ($existing) {
            $status = 'SKIPPED';
            $errorMessage = 'Ya existe bloqueo activo';
          } else {
            $blockId = $blockRepo->create([
              'action_number' => $actionNumber,
              'scope_type' => $scopeType,
              'scope_draw_id' => $scopeDrawId,
              'reason' => $reason,
              'source_file_import_id' => $importId,
              'active_from' => $now,
              'inactive_at' => null,
            ], $ctx->userId);
            $blockRepo->logAudit($blockId, 'BLOCK', $reason, $now, $importId, $ctx->userId);
          }
        } else { // UNBLOCK
          $existing = $blockRepo->findActiveBlock($actionNumber, $scopeType, $scopeDrawId);
          if (!$existing) {
            $status = 'SKIPPED';
            $errorMessage = 'No existe bloqueo activo';
          } else {
            $blockRepo->unblock((int)$existing['id'], $now, $ctx->userId);
            $blockRepo->logAudit((int)$existing['id'], 'UNBLOCK', $reason, $now, $importId, $ctx->userId);
          }
        }

      } catch (\Throwable $e) {
        $status = 'ERROR';
        $errorMessage = $e instanceof HttpException ? $e->getMessage() : $e->getMessage();
      }

      if ($status === 'OK' || $status === 'SKIPPED') $ok++;
      if ($status === 'ERROR') $err++;

      $importRepo->insertRow([
        'file_import_id' => $importId,
        'row_number' => $rowNumber,
        // Guardamos siempre un número (0) cuando el actionNumber viene inválido, para evitar constraints NOT NULL.
        'action_number' => (ctype_digit($actionRaw) ? (int)$actionRaw : 0),
        'scope_type' => $scopeType,
        'scope_draw_id' => $scopeDrawId,
        'operation' => $operation,
        // Guardamos string (vacío si viene vacío) para evitar constraints NOT NULL.
        'reason' => $reason,
        'result_status' => $status,
        'error_message' => $errorMessage,
        'active_from' => gmdate('Y-m-d H:i:s'),
      ], $ctx->userId);
    }

    fclose($handle);

    $importRepo->setImportResult($importId, 'PROCESSED', $total, $ok, $err, null, $ctx->userId);

    $res->json(200, [
      'ok' => true,
      'data' => [
        'importId' => $importId,
        'processStatus' => 'PROCESSED',
        'rowsTotal' => $total,
        'rowsOk' => $ok,
        'rowsError' => $err,
      ],
      'error' => null,
    ]);
  }

  public function getImport(int $importId, Request $req, Response $res): void
  {
    $repo = new ImportRepository($this->db);
    $imp = $repo->getImportById($importId);
    if (!$imp) throw new HttpException(404, 'NOT_FOUND', 'Import no existe');

    $res->json(200, [
      'ok' => true,
      'data' => [
        'id' => (int)$imp['id'],
        'importType' => $imp['import_type'],
        'originalFilename' => $imp['original_filename'],
        'uploadedAt' => $imp['uploaded_at'],
        'uploadedByUserId' => (int)$imp['uploaded_by_user_id'],
        'processStatus' => $imp['process_status'],
        'rowsTotal' => $imp['rows_total'] !== null ? (int)$imp['rows_total'] : null,
        'rowsOk' => $imp['rows_ok'] !== null ? (int)$imp['rows_ok'] : null,
        'rowsError' => $imp['rows_error'] !== null ? (int)$imp['rows_error'] : null,
        'notes' => $imp['notes'],
        'createdAt' => $imp['created_at'],
        'updatedAt' => $imp['updated_at'],
      ],
      'error' => null,
    ]);
  }

  public function listImportRows(int $importId, Request $req, Response $res): void
  {
    $status = strtoupper(trim((string)$req->query('status', '')));
    $statusFilter = null;
    if ($status !== '') {
      if (!in_array($status, ['OK','ERROR','SKIPPED'], true)) {
        throw new HttpException(400, 'VALIDATION', 'status inválido (OK|ERROR|SKIPPED)');
      }
      $statusFilter = $status;
    }

    $page = max(1, (int)$req->query('page', 1));
    $pageSize = min(200, max(1, (int)$req->query('pageSize', 100)));

    $repo = new ImportRepository($this->db);
    // ensure import exists
    if (!$repo->getImportById($importId)) throw new HttpException(404, 'NOT_FOUND', 'Import no existe');

    $rows = $repo->listRows($importId, $statusFilter, $page, $pageSize);

    $res->json(200, [
      'ok' => true,
      'data' => [
        'items' => array_map(function ($r) {
          return [
            'id' => (int)$r['id'],
            'rowNumber' => (int)$r['row_number'],
            'actionNumber' => $r['action_number'] !== null ? (int)$r['action_number'] : null,
            'scopeType' => $r['scope_type'],
            'scopeDrawId' => $r['scope_draw_id'] !== null ? (int)$r['scope_draw_id'] : null,
            'operation' => $r['operation'],
            'reason' => $r['reason'],
            'resultStatus' => $r['result_status'],
            'errorMessage' => $r['error_message'],
            'createdAt' => $r['created_at'],
          ];
        }, $rows),
        'page' => $page,
        'pageSize' => $pageSize,
      ],
      'error' => null,
    ]);
  }
}
