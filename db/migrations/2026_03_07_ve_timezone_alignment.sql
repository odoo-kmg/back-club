-- Alineación de zona horaria a Venezuela (America/Caracas / UTC-04:00)
-- Uso sugerido: ejecutar primero en QA / staging y validar exportes + aperturas/cierres.

SET time_zone = '-04:00';

SELECT NOW() AS now_session_ve,
       UTC_TIMESTAMP() AS now_utc,
       TIMEDIFF(NOW(), UTC_TIMESTAMP()) AS ve_offset,
       @@session.time_zone AS session_tz,
       @@global.time_zone AS global_tz;

-- 1) Inventario de columnas de fecha/hora
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND DATA_TYPE IN ('timestamp', 'datetime', 'date')
ORDER BY TABLE_NAME, COLUMN_NAME;

-- 2) Generador de ALTER para migrar TIMESTAMP -> DATETIME en columnas de negocio
--    Revisar el output antes de ejecutar.
SELECT CONCAT(
  'ALTER TABLE `', TABLE_NAME, '` MODIFY `', COLUMN_NAME, '` DATETIME',
  CASE WHEN IS_NULLABLE = 'NO' THEN ' NOT NULL' ELSE ' NULL' END,
  CASE
    WHEN COLUMN_DEFAULT IS NULL THEN ''
    WHEN COLUMN_DEFAULT = 'CURRENT_TIMESTAMP' THEN ' DEFAULT CURRENT_TIMESTAMP'
    ELSE CONCAT(' DEFAULT ''', COLUMN_DEFAULT, '''')
  END,
  ';'
) AS suggested_alter
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND DATA_TYPE = 'timestamp'
  AND COLUMN_NAME IN (
    'reg_open_at', 'reg_close_at', 'registered_at', 'selected_at', 'reveal_at',
    'inactive_at', 'executed_at', 'active_from', 'created_at', 'updated_at',
    'uploaded_at', 'start_date', 'end_date', 'use_start_date', 'use_end_date'
  )
ORDER BY TABLE_NAME, COLUMN_NAME;

-- 3) Muestreo rápido de tablas sensibles
SELECT 'draw' AS tbl, id, reg_open_at, reg_close_at, active_from, inactive_at, created_at, updated_at
FROM `draw`
ORDER BY id DESC
LIMIT 20;

SELECT 'draw_participant' AS tbl, id, draw_id, registered_at, inactive_at, created_at, updated_at
FROM draw_participant
ORDER BY id DESC
LIMIT 20;

SELECT 'draw_winner' AS tbl, id, draw_id, selected_at, reveal_at, inactive_at, created_at, updated_at
FROM draw_winner
ORDER BY id DESC
LIMIT 20;

SELECT 'draw_execution' AS tbl, id, draw_id, started_at, finished_at, created_at, updated_at
FROM draw_execution
ORDER BY id DESC
LIMIT 20;

-- 4) Si detectas data histórica guardada en UTC y quieres corregirla, hazlo tabla por tabla.
--    EJEMPLO (NO ejecutar sin validar previamente):
--    UPDATE draw_winner
--       SET selected_at = DATE_SUB(selected_at, INTERVAL 4 HOUR),
--           reveal_at   = CASE WHEN reveal_at IS NULL THEN NULL ELSE DATE_SUB(reveal_at, INTERVAL 4 HOUR) END
--     WHERE id IN (...);
