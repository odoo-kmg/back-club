<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\ImportRepository;
use App\Repositories\ShareholderRepository;
use App\Security\AuthContext;
use PDO;

final class ShareholderController
{
  private PDO $db;

  public function __construct(PDO $db)
  {
    $this->db = $db;
  }

  public function list(Request $req, Response $res): void
  {
    $includeInactive = $this->toBool($req->query('includeInactive', 'false'));
    $status = strtoupper(trim((string)$req->query('status', '')));
    $search = trim((string)$req->query('search', ''));
    $page = (int)$req->query('page', 1);
    $pageSize = (int)$req->query('pageSize', 50);

    $repo = new ShareholderRepository($this->db);
    $result = $repo->list([
      'status' => $status !== '' ? $status : null,
      'search' => $search !== '' ? $search : null,
      'page' => $page,
      'page_size' => $pageSize,
    ], $includeInactive);

    $items = array_map([$this, 'mapRow'], $result['items']);

    $res->json(200, [
      'ok' => true,
      'data' => [
        'items' => $items,
        'page' => max(1, $page),
        'pageSize' => min(200, max(1, $pageSize)),
        'total' => $result['total'],
      ],
      'error' => null,
    ]);
  }

  public function create(AuthContext $ctx, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $payload = $this->normalizePayload($body);

    $repo = new ShareholderRepository($this->db);
    $existingMatch = $repo->findActiveMatch($payload['action_number'], $payload['document_key']);
    if ($existingMatch) {
      throw new HttpException(409, 'ACTION_DOCUMENT_ALREADY_EXISTS', 'Ya existe una cédula/documento vigente para esa acción');
    }

    $byDocument = $repo->findActiveByDocumentKey($payload['document_key']);
    if ($byDocument) {
      throw new HttpException(409, 'DOCUMENT_ALREADY_EXISTS', 'Ya existe un accionista vigente para ese documento');
    }

    $id = $repo->create($payload, $ctx->userId);
    $row = $repo->getById($id);

    $res->json(201, ['ok' => true, 'data' => $this->mapRow($row ?: []), 'error' => null]);
  }

  public function patch(AuthContext $ctx, int $id, Request $req, Response $res): void
  {
    $body = $req->json();
    if (!$body) throw new HttpException(400, 'BAD_JSON', 'JSON inválido o vacío');

    $repo = new ShareholderRepository($this->db);
    $current = $repo->getById($id);
    if (!$current) throw new HttpException(404, 'NOT_FOUND', 'Accionista no existe');
    if ($current['inactive_at'] !== null) throw new HttpException(409, 'INACTIVE_RECORD', 'El registro ya está inactivo');

    $merged = [
      'actionNumber' => $body['actionNumber'] ?? $current['action_number'],
      'documentType' => $body['documentType'] ?? $current['document_type'],
      'documentNumber' => $body['documentNumber'] ?? $current['document_number'],
      'firstName' => array_key_exists('firstName', $body) ? $body['firstName'] : $current['first_name'],
      'lastName' => array_key_exists('lastName', $body) ? $body['lastName'] : $current['last_name'],
      'phoneE164' => array_key_exists('phoneE164', $body) ? $body['phoneE164'] : $current['phone_e164'],
      'email' => array_key_exists('email', $body) ? $body['email'] : $current['email'],
      'sourceFileImportId' => $current['source_file_import_id'],
    ];

    $payload = $this->normalizePayload($merged);

    $existingMatch = $repo->findActiveMatch($payload['action_number'], $payload['document_key']);
    if ($existingMatch && (int)$existingMatch['id'] !== $id) {
      throw new HttpException(409, 'ACTION_DOCUMENT_ALREADY_EXISTS', 'Ya existe una cédula/documento vigente para esa acción');
    }

    $byDocument = $repo->findActiveByDocumentKey($payload['document_key']);
    if ($byDocument && (int)$byDocument['id'] !== $id) {
      throw new HttpException(409, 'DOCUMENT_ALREADY_EXISTS', 'Ya existe un accionista vigente para ese documento');
    }

    if ($repo->sameBusinessData($current, $payload)) {
      $res->json(200, ['ok' => true, 'data' => $this->mapRow($current), 'error' => null]);
      return;
    }

    $now = date('Y-m-d H:i:s');
    $this->db->beginTransaction();
    try {
      $repo->deactivate($id, $now, $ctx->userId);
      $newId = $repo->create($payload, $ctx->userId);
      $this->db->commit();
      $row = $repo->getById($newId);
      $res->json(200, ['ok' => true, 'data' => $this->mapRow($row ?: []), 'error' => null]);
      return;
    } catch (\Throwable $e) {
      if ($this->db->inTransaction()) $this->db->rollBack();
      throw $e;
    }
  }

  public function delete(AuthContext $ctx, int $id, Response $res): void
  {
    $repo = new ShareholderRepository($this->db);
    $current = $repo->getById($id);
    if (!$current) throw new HttpException(404, 'NOT_FOUND', 'Accionista no existe');

    if ($current['inactive_at'] === null) {
      $repo->deactivate($id, date('Y-m-d H:i:s'), $ctx->userId);
    }

    $res->json(200, ['ok' => true, 'data' => ['id' => $id, 'status' => 'INACTIVE'], 'error' => null]);
  }

  public function importCsv(AuthContext $ctx, Request $req, Response $res): void
  {
    $file = $req->file('file');
    if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      throw new HttpException(400, 'VALIDATION', 'Archivo CSV requerido');
    }

    $originalFilename = (string)($file['name'] ?? 'shareholders.csv');
    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
      throw new HttpException(400, 'VALIDATION', 'Archivo inválido');
    }

    $importRepo = new ImportRepository($this->db);
    $importId = $importRepo->createImport([
      'import_type' => 'SHAREHOLDER',
      'original_filename' => $originalFilename,
      'uploaded_at' => date('Y-m-d H:i:s'),
      'uploaded_by_user_id' => $ctx->userId,
      'process_status' => 'PENDING',
      'notes' => null,
    ], $ctx->userId);

    $handle = fopen($tmpPath, 'rb');
    if (!$handle) {
      $importRepo->setImportResult($importId, 'FAILED', 0, 0, 0, 'No se pudo abrir el archivo', $ctx->userId);
      throw new HttpException(500, 'SERVER_ERROR', 'No se pudo abrir el archivo');
    }

    $delimiter = $this->detectCsvDelimiter($handle);
    $headers = fgetcsv($handle, 0, $delimiter);
    if (!is_array($headers) || count($headers) === 0) {
      fclose($handle);
      $importRepo->setImportResult($importId, 'FAILED', 0, 0, 0, 'CSV sin cabecera válida', $ctx->userId);
      throw new HttpException(400, 'VALIDATION', 'CSV sin cabecera válida');
    }

    $headerMap = $this->mapCsvHeaders($headers);
    if (!isset($headerMap['action_number']) || !isset($headerMap['document_type']) || !isset($headerMap['document_number'])) {
      fclose($handle);
      $importRepo->setImportResult($importId, 'FAILED', 0, 0, 0, 'Cabecera inválida: requiere acción, tipo y número de documento', $ctx->userId);
      throw new HttpException(400, 'VALIDATION', 'Cabecera inválida: requiere acción, tipo y número de documento');
    }

    $repo = new ShareholderRepository($this->db);
    $rowNumber = 1;
    $total = 0;
    $ok = 0;
    $err = 0;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
      $rowNumber++;
      if ($this->isCsvRowBlank($row)) continue;

      $total++;
      $resultStatus = 'OK';
      $operation = null;
      $errorMessage = null;
      $payload = null;

      try {
        $payload = $this->normalizePayload([
          'actionNumber' => $this->csvValue($row, $headerMap, 'action_number'),
          'documentType' => $this->csvValue($row, $headerMap, 'document_type'),
          'documentNumber' => $this->csvValue($row, $headerMap, 'document_number'),
          'firstName' => $this->csvValue($row, $headerMap, 'first_name'),
          'lastName' => $this->csvValue($row, $headerMap, 'last_name'),
          'phoneE164' => $this->csvValue($row, $headerMap, 'phone_e164'),
          'email' => $this->csvValue($row, $headerMap, 'email'),
          'sourceFileImportId' => $importId,
        ]);

        $this->db->beginTransaction();

        $existingMatch = $repo->findActiveMatch($payload['action_number'], $payload['document_key']);
        $existingByDocument = $repo->findActiveByDocumentKey($payload['document_key']);

        if ($existingByDocument && !$existingMatch && (int)$existingByDocument['action_number'] !== (int)$payload['action_number']) {
          throw new HttpException(409, 'DOCUMENT_ALREADY_EXISTS', 'Ya existe un accionista vigente para ese documento');
        }

        if (!$existingMatch) {
          $repo->create($payload, $ctx->userId);
          $operation = 'INSERT';
        } else {
          if ($repo->sameBusinessData($existingMatch, $payload)) {
            $operation = 'NO_CHANGE';
            $resultStatus = 'SKIPPED';
          } else {
            $repo->updateActive((int)$existingMatch['id'], $payload, $ctx->userId);
            $operation = 'UPDATE';
          }
        }

        $this->db->commit();
      } catch (\Throwable $e) {
        if ($this->db->inTransaction()) $this->db->rollBack();
        $resultStatus = 'ERROR';
        $operation = $operation ?: null;
        $errorMessage = $e->getMessage();
      }

      if ($resultStatus === 'OK' || $resultStatus === 'SKIPPED') $ok++;
      if ($resultStatus === 'ERROR') $err++;

      $importRepo->insertShareholderRow([
        'file_import_id' => $importId,
        'row_number' => $rowNumber,
        'action_number' => $payload['action_number'] ?? null,
        'document_type' => $payload['document_type'] ?? null,
        'document_number' => $payload['document_number'] ?? null,
        'document_key' => $payload['document_key'] ?? null,
        'first_name' => $payload['first_name'] ?? null,
        'last_name' => $payload['last_name'] ?? null,
        'phone_e164' => $payload['phone_e164'] ?? null,
        'email' => $payload['email'] ?? null,
        'operation' => $operation,
        'result_status' => $resultStatus,
        'error_message' => $errorMessage,
        'active_from' => date('Y-m-d H:i:s'),
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

  public function getImport(int $importId, Response $res): void
  {
    $repo = new ImportRepository($this->db);
    $imp = $repo->getImportById($importId);
    if (!$imp || (string)$imp['import_type'] !== 'SHAREHOLDER') {
      throw new HttpException(404, 'NOT_FOUND', 'Import de accionistas no existe');
    }

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
      if (!in_array($status, ['OK', 'ERROR', 'SKIPPED'], true)) {
        throw new HttpException(400, 'VALIDATION', 'status inválido (OK|ERROR|SKIPPED)');
      }
      $statusFilter = $status;
    }

    $page = max(1, (int)$req->query('page', 1));
    $pageSize = min(200, max(1, (int)$req->query('pageSize', 100)));

    $repo = new ImportRepository($this->db);
    $imp = $repo->getImportById($importId);
    if (!$imp || (string)$imp['import_type'] !== 'SHAREHOLDER') {
      throw new HttpException(404, 'NOT_FOUND', 'Import de accionistas no existe');
    }

    $rows = $repo->listShareholderRows($importId, $statusFilter, $page, $pageSize);

    $res->json(200, [
      'ok' => true,
      'data' => [
        'items' => array_map(function ($r) {
          return [
            'id' => (int)$r['id'],
            'rowNumber' => (int)$r['row_number'],
            'actionNumber' => $r['action_number'] !== null ? (int)$r['action_number'] : null,
            'documentType' => $r['document_type'],
            'documentNumber' => $r['document_number'],
            'documentKey' => $r['document_key'],
            'firstName' => $r['first_name'],
            'lastName' => $r['last_name'],
            'phoneE164' => $r['phone_e164'],
            'email' => $r['email'],
            'operation' => $r['operation'],
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

  public function validateActionDocumentMatch(int $actionNumber, string $documentType, string $documentNumber): void
  {
    $normalizedType = $this->normalizeDocumentType($documentType);
    $normalizedNumber = $this->normalizeDocumentNumber($documentNumber);
    $documentKey = $this->buildDocumentKey($normalizedType, $normalizedNumber);

    $repo = new ShareholderRepository($this->db);
    $activeRows = $repo->findActiveListByActionNumber($actionNumber);
    if (count($activeRows) === 0) {
      throw new HttpException(422, 'ACTION_NOT_FOUND_IN_SHAREHOLDER', 'La acción no existe en el padrón de accionistas');
    }

    $match = $repo->findActiveMatch($actionNumber, $documentKey);
    if (!$match) {
      throw new HttpException(422, 'DOCUMENT_MISMATCH_FOR_ACTION', 'El documento no corresponde a la acción indicada');
    }
  }

  private function normalizePayload(array $body): array
  {
    $actionNumber = (int)($body['actionNumber'] ?? 0);
    if ($actionNumber <= 0 || $actionNumber > 7000) {
      throw new HttpException(400, 'VALIDATION', 'actionNumber inválido (1..7000)');
    }

    $documentType = $this->normalizeDocumentType((string)($body['documentType'] ?? ''));
    $documentNumber = $this->normalizeDocumentNumber((string)($body['documentNumber'] ?? ''));
    $documentKey = $this->buildDocumentKey($documentType, $documentNumber);

    $firstName = $this->nullableTrim($body['firstName'] ?? null);
    $lastName = $this->nullableTrim($body['lastName'] ?? null);
    $phone = $this->normalizePhone($body['phoneE164'] ?? null);
    $email = $this->normalizeEmail($body['email'] ?? null);
    $sourceFileImportId = isset($body['sourceFileImportId']) && $body['sourceFileImportId'] !== null && $body['sourceFileImportId'] !== ''
      ? (int)$body['sourceFileImportId']
      : null;

    return [
      'action_number' => $actionNumber,
      'document_type' => $documentType,
      'document_number' => $documentNumber,
      'document_key' => $documentKey,
      'first_name' => $firstName,
      'last_name' => $lastName,
      'phone_e164' => $phone,
      'email' => $email,
      'source_file_import_id' => $sourceFileImportId,
      'active_from' => date('Y-m-d H:i:s'),
    ];
  }

  private function normalizeDocumentType(string $type): string
  {
    $value = strtoupper(trim($type));
    if (!in_array($value, ['V', 'E', 'J'], true)) {
      throw new HttpException(400, 'VALIDATION', 'documentType inválido (V|E|J)');
    }
    return $value;
  }

  private function normalizeDocumentNumber(string $value): string
  {
    $normalized = strtoupper(trim(preg_replace('/[^A-Z0-9]+/', '', (string)$value)));
    if ($normalized === '') {
      throw new HttpException(400, 'VALIDATION', 'documentNumber es requerido');
    }
    if (strlen($normalized) < 6 || strlen($normalized) > 15) {
      throw new HttpException(400, 'VALIDATION', 'documentNumber inválido');
    }
    return $normalized;
  }

  private function buildDocumentKey(string $type, string $number): string
  {
    return $type . '-' . $number;
  }

  private function normalizePhone($value): ?string
  {
    if ($value === null) return null;
    $digits = preg_replace('/\D+/', '', (string)$value);
    if ($digits === '') return null;
    if (strlen($digits) < 10 || strlen($digits) > 15) {
      throw new HttpException(400, 'VALIDATION', 'phoneE164 inválido');
    }
    return $digits;
  }

  private function normalizeEmail($value): ?string
  {
    $email = $this->nullableTrim($value);
    if ($email === null) return null;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new HttpException(400, 'VALIDATION', 'email inválido');
    }
    return $email;
  }

  private function nullableTrim($value): ?string
  {
    if ($value === null) return null;
    $s = trim((string)$value);
    return $s === '' ? null : $s;
  }

  private function toBool($v): bool
  {
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1', 'true', 'yes', 'y', 'on'], true);
  }

  private function mapRow(array $r): array
  {
    return [
      'id' => isset($r['id']) ? (int)$r['id'] : null,
      'actionNumber' => isset($r['action_number']) ? (int)$r['action_number'] : null,
      'documentType' => $r['document_type'] ?? null,
      'documentNumber' => $r['document_number'] ?? null,
      'documentKey' => $r['document_key'] ?? null,
      'firstName' => $r['first_name'] ?? null,
      'lastName' => $r['last_name'] ?? null,
      'phoneE164' => $r['phone_e164'] ?? null,
      'email' => $r['email'] ?? null,
      'sourceFileImportId' => isset($r['source_file_import_id']) && $r['source_file_import_id'] !== null ? (int)$r['source_file_import_id'] : null,
      'status' => (($r['inactive_at'] ?? null) === null) ? 'ACTIVE' : 'INACTIVE',
      'activeFrom' => $r['active_from'] ?? null,
      'inactiveAt' => $r['inactive_at'] ?? null,
      'createdAt' => $r['created_at'] ?? null,
      'updatedAt' => $r['updated_at'] ?? null,
      'createdBy' => isset($r['created_by']) && $r['created_by'] !== null ? (int)$r['created_by'] : null,
      'updatedBy' => isset($r['updated_by']) && $r['updated_by'] !== null ? (int)$r['updated_by'] : null,
    ];
  }

  private function detectCsvDelimiter($handle): string
  {
    $pos = ftell($handle);
    $firstLine = fgets($handle);
    if ($firstLine === false) {
      fseek($handle, $pos);
      return ',';
    }
    fseek($handle, $pos);

    $comma = substr_count($firstLine, ',');
    $semicolon = substr_count($firstLine, ';');
    return $semicolon > $comma ? ';' : ',';
  }

  /** @return array<string,int> */
  private function mapCsvHeaders(array $headers): array
  {
    $map = [];
    foreach ($headers as $idx => $header) {
      $key = strtolower(trim((string)$header));
      $key = preg_replace('/\s+/', '_', $key);
      $key = str_replace(['á','é','í','ó','ú','ñ'], ['a','e','i','o','u','n'], $key);
      switch ($key) {
        case 'accion':
        case 'action':
        case 'actionnumber':
        case 'action_number':
        case 'numero_accion':
        case 'nro_accion':
          $map['action_number'] = $idx;
          break;
        case 'tipo':
        case 'tipo_documento':
        case 'tipo_identificacion':
        case 'documenttype':
        case 'document_type':
          $map['document_type'] = $idx;
          break;
        case 'documento':
        case 'cedula':
        case 'numero_documento':
        case 'numero_identificacion':
        case 'documentnumber':
        case 'document_number':
          $map['document_number'] = $idx;
          break;
        case 'nombre':
        case 'firstname':
        case 'first_name':
          $map['first_name'] = $idx;
          break;
        case 'apellido':
        case 'lastname':
        case 'last_name':
          $map['last_name'] = $idx;
          break;
        case 'telefono':
        case 'telefono_celular':
        case 'celular':
        case 'phone':
        case 'phonee164':
        case 'phone_e164':
          $map['phone_e164'] = $idx;
          break;
        case 'correo':
        case 'email':
        case 'correo_electronico':
          $map['email'] = $idx;
          break;
      }
    }
    return $map;
  }

  private function csvValue(array $row, array $headerMap, string $key)
  {
    if (!isset($headerMap[$key])) return null;
    $idx = $headerMap[$key];
    return array_key_exists($idx, $row) ? $row[$idx] : null;
  }

  private function isCsvRowBlank(array $row): bool
  {
    foreach ($row as $v) {
      if (trim((string)$v) !== '') return false;
    }
    return true;
  }
}
