# Club Puerto Azul – Sorteos API

API REST para la gestión integral de **sorteos del Club Puerto Azul**. Permite administrar catálogos, sorteos, registro de participantes, bloqueos por acción, ejecución de sorteos, auditoría y exportación de resultados.

---

## Base URL

- **DEV:** https://sorteos.kmg.com.ve/api
- **PROD:** https://sorteos.clubpuertoazul.com/api
- **Base path:** `/api/v1`

---

## Autenticación

La API soporta dos mecanismos de seguridad:

- **JWT Bearer Token** (uso interno y administrativo)
  - Header: `Authorization: Bearer <token>`
- **API Key** (integraciones externas)
  - Header: `X-API-Key: <key>`

---

## Envelope estándar de respuestas

```json
{
  "ok": true,
  "data": {},
  "error": null
}
```

En caso de error:

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

---

## Módulos del API

- Health
- Auth
- Security / Admin
- Catalogs
- Draws
- Participants
- Action Blocks
- Imports
- Executions
- Reports
- Audit
- External

---

## Documentación

- OpenAPI: `openapi-club-puerto-azul.yaml`

---

## Licencia

Uso interno – Club Puerto Azul
