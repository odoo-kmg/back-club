# Phase 3 (delta)

Este zip contiene **solo archivos nuevos o modificados** para aplicar la Fase 3 (Operación) sobre un backend ya instalado con Fase 2.

## Qué incluye
- Participants:
  - GET  /api/v1/draws/{id}/participants
  - POST /api/v1/draws/{id}/participants
  - POST /api/v1/participants/{id}/cancel
- Action blocks:
  - GET/POST/PATCH /api/v1/action-blocks
  - POST /api/v1/action-blocks/{id}/unblock
- Imports (CSV action blocks):
  - POST /api/v1/imports/action-blocks (multipart, field: file)
  - GET  /api/v1/imports/{id}
  - GET  /api/v1/imports/{id}/rows?status=ERROR|OK|SKIPPED

## Cómo aplicar
1) Haz backup de la carpeta `api/` actual (ej: `api__backup_YYYYMMDD`).
2) Extrae este zip **encima** del docroot del subdominio, de forma que:
   - `api/index.php` se reemplace
   - `api/src/Http/Request.php` se reemplace
   - se agreguen los nuevos Controllers y Repositories.

En cPanel: si al extraer no sobrescribe, sube los archivos manualmente y reemplaza.

## Nota
- Importación soporta **CSV** solamente en esta fase (para mantener simple).
