# Backend Club Puerto Azul - Fase 2

Incluye:

- Seguridad (JWT + roles/permisos) y mejoras de manejo de errores.
- Catálogos (Fase 1): events, resource-types, participation-scopes.
- Sorteos (Fase 2): draws, open/close registration, eligibility-ranges, exclusions.

## Deploy

1) Copiar la carpeta `back-club` como `api/` dentro del docroot del subdominio.
2) Editar `config/app.php` (DB + JWT secret + CORS).
3) Validar: `GET /api/v1/ping`.

## Nota

El archivo `tools/hash.php` es solo para bootstrap de hashes. En producción debe borrarse.
