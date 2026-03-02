# Club Puerto Azul – Sorteos API (PHP + MySQL)

API REST para la plataforma de **sorteos del Club Puerto Azul**: administración de catálogos (eventos, tipos de recurso, scopes), configuración/operación de sorteos, registro de participantes, gestión de bloqueos, ejecución del sorteo (selección de ganadores), auditoría y exportación de resultados.

> Decisión de seguridad: **NO** publicar Swagger UI en PROD.  
> La documentación oficial vive en este README (operación) y el archivo OpenAPI (contrato interno).

---

## 1) URLs y versionado

**Base URL**
- DEV (cPanel): `https://sorteos.kmg.com.ve/api`
- PROD (SiteGround): `https://sorteos.clubpuertoazul.com/api`
- Same-origin: `/api`

**Versionado de API**
- Todas las rutas comienzan con: `/v1/...`

---

## 2) Autenticación y autorización

### 2.1 JWT (usuarios internos – React)
- Header: `Authorization: Bearer <token>`
- El login retorna: `token`, `user`, `roles`, `permissions`.

### 2.2 API Key (integraciones externas – Botmaker)
- Header: `X-API-Key: <key>`
- **No usar API-Key en React** (queda expuesta en el navegador).

---

## 3) Formato estándar de respuestas (Envelope)

### OK
```json
{ "ok": true, "data": {}, "error": null }
```

### Error
```json
{
  "ok": false,
  "data": null,
  "error": { "code": "ERROR_CODE", "message": "Descripción del error" }
}
```

---

## 4) Errores HTTP y códigos más comunes

| HTTP | Cuándo | error.code típico |
|---:|---|---|
| 400 | JSON inválido / validación de campos | BAD_JSON, VALIDATION |
| 401 | No autenticado / token inválido | UNAUTHORIZED, TOKEN_EXPIRED, INVALID_CREDENTIALS |
| 403 | Falta permiso | FORBIDDEN |
| 404 | Recurso no existe | NOT_FOUND |
| 409 | Conflicto de unicidad / ejecución duplicada | DUPLICATE, ACTION_ALREADY_PARTICIPATING_SCOPE, EXECUTION_ALREADY_STARTED |
| 422 | Regla de negocio | REGISTRATION_CLOSED, ACTION_NOT_ELIGIBLE, ACTION_BLOCKED, DRAW_NOT_READY |

---

## 5) Zona horaria (muy importante)

Negocio opera en **hora local Venezuela (UTC-4)**.  
El backend utiliza tiempo UTC internamente, por lo que se recomienda **enviar fechas/hora en UTC con sufijo `Z`**.

Ejemplo: **06:00 AM Venezuela** = **10:00Z**.

---

## 6) Soft delete + auditoría de tablas

Convención en BD:
- `active_from` (DATETIME)
- `inactive_at` (DATETIME, NULL)
- `created_at`, `updated_at`
- `created_by`, `updated_by` (BIGINT, NULL)

Regla operacional:
- un registro está activo si: `active_from <= NOW()` y (`inactive_at` es NULL o `inactive_at > NOW()`).

---

## 7) Conceptos clave del modelo

### 7.1 Event (evento / temporada)
Periodo macro (ej.: Año 2026, Semana Santa 2026).

### 7.2 Resource Type (tipo de recurso)
Tipo de premio/sorteo: CHURUATA, RESIDENCIA, HABITACION, etc.

> Nota: “Playa Mansa/Oceánica” **no es un resource_type**. Se modela como `draw.resource_context` (contexto del sorteo).

### 7.3 Participation Scope (scope de participación)
Entidad para **enforcement fuerte en BD**: una misma `actionNumber` no puede participar dos veces dentro de un mismo scope.

Ejemplo: “Churuatas Viernes 2026-03-06” → scope compartido entre Mansa y Oceánica para que sean excluyentes **solo ese viernes**.

### 7.4 Draw (sorteo)
Instancia operativa:
- evento + tipo de recurso + scope
- ventana de registro (regOpenAt / regCloseAt)
- configuración de ganadores (`winnersCount`) y ritmo (`pickIntervalSeconds`)
- `resourceContext` (ej.: PLAYA_MANSA)

---

## 8) Ciclo de vida de un sorteo (draw.status)

Flujo recomendado:
1. **DRAFT** (configuración)
2. **REG_OPEN** (registro abierto) → se habilita por `open-registration`
3. **REG_CLOSED** (registro cerrado) → `close-registration`
4. **EXECUTING** (sorteo en curso) → `executions/start`
5. **FINISHED** (finalizado) → `executions/{id}/finish`

---

## 9) Ejecución del sorteo (motor) – comportamiento real

- La aleatoriedad se ejecuta **en backend** usando `random_int()` (cripto-seguro).
- El backend **NO** ejecuta un proceso asíncrono cada N segundos.
- El frontend controla el ritmo (ej. cada 2s) llamando `pick-next`.

### 9.1 Concurrencia / multi-navegador (lock implementado)
- Solo puede existir **1 ejecución STARTED por draw**.
- Si otro cliente intenta `start`, obtiene:
  - `409 EXECUTION_ALREADY_STARTED` y `data.executionId` del que está activo.
- Si alguien llama `pick-next` con un `executionId` que no es el activo:
  - `409 EXECUTION_NOT_ACTIVE` y `data.executionId` activo.

### 9.2 Caída del navegador
Si se cierra el navegador durante el sorteo:
- los ganadores ya seleccionados quedaron persistidos en BD (`draw_winner`).
- se retoma consultando `GET /draws/{id}/winners` y continuando con `pick-next` usando el `executionId` activo.

### 9.3 Restart vs Resume
- **resume**: reanuda una ejecución existente (no borra ganadores).
- **restart**: inactiva ganadores activos y crea una nueva ejecución (todo auditado).

---

## 10) Auditoría y transparencia

La auditoría se registra en `audit_event`:
- acciones de configuración/operación,
- y **cada ganador** (`EXEC_PICK_NEXT`) con actor, drawId, executionId, winnerOrder y actionNumber.

Endpoint:
- `GET /v1/audit-events` (permiso `AUDIT_EVENT_READ`)

---

# 11) Quickstart (curl)

> Define `BASE_URL`:
```bash
export BASE_URL="https://sorteos.kmg.com.ve/api"
```

### 11.1 Ping
```bash
curl -sS "$BASE_URL/v1/ping"
```

### 11.2 Login
```bash
curl -sS -X POST "$BASE_URL/v1/auth/login"   -H "Content-Type: application/json"   -d '{"username":"admin","password":"Caracas10"}'
```

---

# 12) Endpoints por módulo (con comentarios operativos)

> Todas las rutas asumen `Content-Type: application/json` y JWT salvo que se indique lo contrario.

## 12.1 Health
- `GET /v1/ping`  
  Health check (sin auth).

## 12.2 Auth
- `POST /v1/auth/login` (sin auth)  
  Autenticación de usuario y entrega de JWT.
- `GET /v1/auth/me`  
  Devuelve sesión actual: usuario, roles y permisos.

## 12.3 Catalogs
### Events
- `GET /v1/events`  
  Lista eventos/temporadas.
- `POST /v1/events`  
  Crea evento.
- `PATCH /v1/events/{eventId}`  
  Actualiza campos de evento (parcial).

### Resource Types
- `GET /v1/resource-types`
- `POST /v1/resource-types`
- `PATCH /v1/resource-types/{resourceTypeId}`

### Participation Scopes
- `GET /v1/participation-scopes?eventId=&resourceTypeId=`  
  Lista scopes (filtrable).
- `POST /v1/participation-scopes`  
  Crea scope. (Usar para exclusión cross-draw y unicidad por acción).
- `PATCH /v1/participation-scopes/{scopeId}`

## 12.4 Draws (configuración y operación)
- `GET /v1/draws?eventId=&status=&forRegistration=true|false`  
  Lista sorteos.  
  - `forRegistration=true` filtra a los “operativos para registro” (para UI de registro).
- `POST /v1/draws`  
  Crea sorteo (queda en `DRAFT`).
- `GET /v1/draws/{drawId}`  
  Detalle de sorteo.
- `PATCH /v1/draws/{drawId}`  
  Actualiza sorteo.

### Ventana de registro (cambia status)
- `POST /v1/draws/{drawId}/open-registration`  
  Pasa a `REG_OPEN`. Para pruebas se permite `{ "force": true }`.
- `POST /v1/draws/{drawId}/close-registration`  
  Pasa a `REG_CLOSED`.

### Rangos de elegibilidad
- `GET /v1/draws/{drawId}/eligibility-ranges`
- `POST /v1/draws/{drawId}/eligibility-ranges`  
  Define rangos (ej. 0001–2000).
- `PATCH /v1/eligibility-ranges/{rangeId}`  
  Permite desactivar con `inactiveAt`.

### Exclusiones entre sorteos
- `GET /v1/draws/{drawId}/exclusions`
- `POST /v1/draws/{drawId}/exclusions`  
  Crea regla:
  - `ruleType=PARTICIPATION` excluye por participación en draw fuente
  - `ruleType=WINNER` excluye por ganador en draw fuente
- `PATCH /v1/exclusions/{exclusionId}`  
  Inactivar regla.

## 12.5 Participants (registro)
- `GET /v1/draws/{drawId}/participants`  
  Lista participantes (search/status/page/pageSize).
- `POST /v1/draws/{drawId}/participants`  
  Registra participante. Validaciones:
  - draw debe estar `REG_OPEN` y dentro de ventana
  - rango elegible
  - no bloqueado (GLOBAL/DRAW)
  - no duplicado por participation_scope
- `POST /v1/participants/{participantId}/cancel`  
  Cancelación lógica (requiere `reason`).

## 12.6 Blocks (acciones no elegibles) + Imports
### Bloqueos manuales
- `GET /v1/action-blocks?scopeType=&scopeDrawId=&actionNumber=&includeInactive=`  
- `POST /v1/action-blocks`  
  Crea bloqueo: `scopeType=GLOBAL|DRAW`.
- `PATCH /v1/action-blocks/{blockId}`  
  Cambia reason/fechas (incluye `inactiveAt`).
- `POST /v1/action-blocks/{blockId}/unblock`  
  Desbloquea (set `inactiveAt=now`, requiere `reason`).

### Import de bloqueos (CSV)
- `POST /v1/imports/action-blocks`  
  Importación **CSV** via `multipart/form-data` con field `file`.  
  Devuelve `importId` + conteos.
- `GET /v1/imports/{importId}`  
  Resumen de import.
- `GET /v1/imports/{importId}/rows?status=OK|ERROR|SKIPPED`  
  Detalle de filas.

> Nota: en esta versión se soporta **CSV** (XLSX queda planificado).

## 12.7 Executions + Winners
- `POST /v1/draws/{drawId}/executions/start`  
  Inicia ejecución (solo si registro está cerrado).  
  Lock: 409 si ya hay una ejecución STARTED (retorna executionId activo).
- `POST /v1/draws/{drawId}/executions/{executionId}/pick-next`  
  Selecciona siguiente ganador (server-side).  
  409 si executionId no es el activo.
- `POST /v1/draws/{drawId}/executions/{executionId}/finish`  
  Finaliza ejecución y marca draw FINISHED.
- `POST /v1/draws/{drawId}/executions/resume`  
  Reanuda ejecución (body: `executionId` o `baseExecutionId` legacy).
- `POST /v1/draws/{drawId}/executions/restart`  
  Reinicia ejecución (requiere `reason`), inactiva ganadores activos y crea nueva ejecución.
- `GET /v1/draws/{drawId}/winners`  
  Lista ganadores en orden (`winnerOrder`).

## 12.8 Reports
- `GET /v1/draws/{drawId}/export?format=json`  
  Export **JSON** (recomendado).  
  La UI genera XLSX localmente (más simple/rápido y evita libs server-side).

## 12.9 Audit
- `GET /v1/audit-events?from=&to=&actionCode=&entityType=&entityId=&page=&pageSize=`  
  Listado de auditoría.

## 12.10 External (Botmaker)
> Estos endpoints no usan JWT, usan `X-API-Key`.

- `POST /v1/external/botmaker/register`  
  Registro desde WhatsApp aplicando mismas reglas del backend.
- `POST /v1/external/botmaker/interaction`  
  Bitácora libre (payload completo).

---

## 13) Postman (recomendado)
- Importar la colección desde OpenAPI **en formato JSON** (Postman es más estable así).
- Mantener un Environment con:
  - `BASE_URL`
  - `TOKEN` (auto-set al hacer login)
  - `BOTMAKER_API_KEY`

---

## 14) Seguridad operativa (mínimo indispensable)
- `config/app.php`: no exponer secretos en repositorios públicos.
- `app.debug=false` en PROD.
- CORS restringido al/los dominios reales.
- Proteger `/api/docs` si algún día se publica documentación (Basic Auth / allowlist IP).
- Rotar `X-API-Key` de Botmaker si se sospecha filtración.

---

## Licencia
Uso interno – Club Puerto Azul
