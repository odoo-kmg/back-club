# Club Puerto Azul – Sorteos API

API REST para la plataforma de **sorteos del Club Puerto Azul**. Permite administrar catálogos (eventos/temporadas, tipos de recurso y scopes), crear y operar sorteos, registrar participantes, aplicar bloqueos por número de acción, ejecutar sorteos (selección de ganadores), auditar operaciones y exportar resultados. citeturn3search2

---

## Base URL

- **DEV (cPanel):** `https://sorteos.kmg.com.ve/api` citeturn3search2
- **PROD (SiteGround):** `https://sorteos.clubpuertoazul.com/api` citeturn3search2
- **Same-origin:** `/api` citeturn3search2
- **Base path:** `/api/v1` citeturn3search2

---

## Autenticación

La API soporta dos mecanismos de seguridad: citeturn3search2

### 1) JWT (Bearer)
- Header: `Authorization: Bearer <token>` citeturn3search2

### 2) API Key (integraciones externas)
- Header: `X-API-Key: <key>` citeturn3search2

---

## Formato estándar de respuestas (Envelope)

### Respuesta OK
```json
{
  "ok": true,
  "data": {},
  "error": null
}
```
citeturn3search2

### Respuesta Error
```json
{
  "ok": false,
  "data": null,
  "error": {
    "code": "ERROR_CODE",
    "message": "Descripción del error"
  }
}
```
citeturn3search2

---

## Quickstart

### Health check
```bash
curl -sS "$BASE_URL/v1/ping"
```
citeturn3search2

### Login (JWT)
```bash
curl -sS -X POST "$BASE_URL/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"username":"<user>","password":"<pass>"}'
```
citeturn3search2

> Nota: el contrato de login retorna `token`, `user`, `roles` y `permissions`. citeturn3search2

---

## Módulos

La especificación OpenAPI está organizada en tags: Health, Auth, Security, Catalogs, Draws, Participants, Blocks, Executions, Reports, Audit, External. citeturn3search2

---

## Tabla de Endpoints (por módulo)

> Esta tabla es un **índice** rápido. El detalle completo de request/response está en `openapi-club-puerto-azul.yaml`. citeturn3search2

### Health

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/v1/ping` | Health check |
citeturn3search2

### Auth

| Método | Endpoint | Descripción |
|---|---|---|
| POST | `/v1/auth/login` | Login (JWT) |
| GET | `/v1/auth/me` | Sesión actual (usuario/roles/permisos) |
citeturn3search2

### Security / Admin

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/v1/admin/users` | Listar usuarios |
| POST | `/v1/admin/users` | Crear usuario |
| PATCH | `/v1/admin/users/{userId}` | Actualizar usuario (parcial) |
| PUT | `/v1/admin/users/{userId}/roles` | Reemplazar roles de usuario |
| GET | `/v1/admin/roles` | Listar roles |
| POST | `/v1/admin/roles` | Crear rol |
| GET | `/v1/admin/permissions` | Listar permisos |
| PUT | `/v1/admin/roles/{roleId}/permissions` | Reemplazar permisos del rol |
citeturn3search2

### Catalogs

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/v1/events` | Listar eventos/temporadas |
| POST | `/v1/events` | Crear evento |
| PATCH | `/v1/events/{eventId}` | Actualizar evento |
| GET | `/v1/resource-types` | Listar tipos de recurso |
| POST | `/v1/resource-types` | Crear tipo de recurso |
| PATCH | `/v1/resource-types/{resourceTypeId}` | Actualizar tipo de recurso |
| GET | `/v1/participation-scopes` | Listar scopes (filtros: eventId, resourceTypeId) |
| POST | `/v1/participation-scopes` | Crear scope |
| PATCH | `/v1/participation-scopes/{scopeId}` | Actualizar scope |
citeturn3search2

### Draws

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/v1/draws` | Listar sorteos (filtros: eventId, status, forRegistration) |
| POST | `/v1/draws` | Crear sorteo |
| GET | `/v1/draws/{drawId}` | Obtener detalle de sorteo |
| PATCH | `/v1/draws/{drawId}` | Actualizar sorteo |
| POST | `/v1/draws/{drawId}/open-registration` | Abrir registro |
| POST | `/v1/draws/{drawId}/close-registration` | Cerrar registro |
| GET | `/v1/draws/{drawId}/eligibility-ranges` | Listar rangos de elegibilidad |
| POST | `/v1/draws/{drawId}/eligibility-ranges` | Crear rango de elegibilidad |
| PATCH | `/v1/eligibility-ranges/{rangeId}` | Actualizar rango (incl. inactiveAt) |
| GET | `/v1/draws/{drawId}/exclusions` | Listar reglas de exclusión |
| POST | `/v1/draws/{drawId}/exclusions` | Crear regla de exclusión |
| PATCH | `/v1/exclusions/{exclusionId}` | Actualizar exclusión (incl. inactiveAt) |
citeturn3search2

### Participants

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/v1/draws/{drawId}/participants` | Listar participantes del sorteo (search, status, paginación) |
| POST | `/v1/draws/{drawId}/participants` | Registrar participante |
| POST | `/v1/participants/{participantId}/cancel` | Cancelar registro (requiere reason) |
citeturn3search2

### Blocks (Action Blocks + Imports)

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/v1/action-blocks` | Listar bloqueos (filtros: scopeType, scopeDrawId, actionNumber) |
| POST | `/v1/action-blocks` | Crear bloqueo |
| PATCH | `/v1/action-blocks/{blockId}` | Actualizar bloqueo (incl. inactiveAt) |
| POST | `/v1/action-blocks/{blockId}/unblock` | Desbloquear (inactiveAt=now; requiere reason) |
| POST | `/v1/imports/action-blocks` | Importar bloqueos desde archivo (CSV/XLSX) multipart `file` |
| GET | `/v1/imports/{importId}` | Ver resumen de importación |
| GET | `/v1/imports/{importId}/rows` | Ver filas importadas (filtro: status=OK|ERROR|SKIPPED) |
citeturn3search2

### Executions (Ejecución + Ganadores)

| Método | Endpoint | Descripción |
|---|---|---|
| POST | `/v1/draws/{drawId}/executions/start` | Iniciar ejecución |
| POST | `/v1/draws/{drawId}/executions/{executionId}/pick-next` | Seleccionar siguiente ganador |
| POST | `/v1/draws/{drawId}/executions/{executionId}/finish` | Finalizar ejecución |
| POST | `/v1/draws/{drawId}/executions/resume` | Reanudar ejecución (requiere baseExecutionId) |
| POST | `/v1/draws/{drawId}/executions/restart` | Reiniciar ejecución (requiere baseExecutionId, reason) |
| GET | `/v1/draws/{drawId}/winners` | Listar ganadores |
citeturn3search2

### Reports

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/v1/draws/{drawId}/export` | Exportar resultados (format=json|xlsx) |
citeturn3search2

### Audit

| Método | Endpoint | Descripción |
|---|---|---|
| GET | `/v1/audit-events` | Listar auditoría (filtros: from, to, entityType, entityId, actionCode) |
citeturn3search2

### External (Botmaker)

> Estos endpoints usan **API Key** (`X-API-Key`). citeturn3search2

| Método | Endpoint | Descripción |
|---|---|---|
| POST | `/v1/external/botmaker/register` | Registro de participante desde Botmaker |
| POST | `/v1/external/botmaker/interaction` | Log de interacción Botmaker |
citeturn3search2

---

## Parámetros comunes

- `page` (default 1), `pageSize` (default 50, max 500) citeturn3search2
- `search` (búsqueda textual) citeturn3search2
- `includeInactive` (soft delete) citeturn3search2

---

## Reglas de negocio (alto nivel)

- **Soft delete**: múltiples recursos usan `inactiveAt` para desactivar sin borrar. citeturn3search2
- **Bloqueos**: `scopeType` puede ser `GLOBAL` o `DRAW` (y opcionalmente `scopeDrawId`). citeturn3search2
- **Exclusiones**: reglas `ruleType` incluyen `PARTICIPATION` y `WINNER` apuntando a `targetDrawId`. citeturn3search2
- **Registro de participantes**: requiere `actionNumber` y `channel` (`ASSISTED`, `WEB`, `WHATSAPP`). citeturn3search2

---

## Fuente de verdad

- Especificación OpenAPI: `openapi-club-puerto-azul.yaml` citeturn3search2

---

## Ambientes

- **DEV**: pruebas, imports y validación de ejecuciones. citeturn3search2
- **PROD**: operación real (acceso restringido). citeturn3search2

---

## Licencia

Uso interno – Club Puerto Azul
