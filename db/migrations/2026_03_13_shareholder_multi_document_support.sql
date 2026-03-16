-- =========================================================
-- Club Puerto Azul - Delta v22
-- Permitir múltiples cédulas/documentos activos por acción
-- Fecha: 2026-03-13
-- Nota: no cambia estructura de columnas productivas.
--       Solo ajusta índices/constraints de unicidad por acción.
-- =========================================================

START TRANSACTION;

SET @db_name := DATABASE();

SET @drop_uq_action := (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.statistics
      WHERE table_schema = @db_name
        AND table_name = 'shareholder'
        AND index_name = 'uq_shareholder_action_active'
    ),
    'ALTER TABLE `shareholder` DROP INDEX `uq_shareholder_action_active`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @drop_uq_action;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @drop_idx_action_active := (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.statistics
      WHERE table_schema = @db_name
        AND table_name = 'shareholder'
        AND index_name = 'idx_shareholder_action_active'
    ),
    'ALTER TABLE `shareholder` DROP INDEX `idx_shareholder_action_active`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @drop_idx_action_active;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @create_idx_action_active := (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.statistics
      WHERE table_schema = @db_name
        AND table_name = 'shareholder'
        AND index_name = 'idx_shareholder_action_active'
    ),
    'SELECT 1',
    'ALTER TABLE `shareholder` ADD KEY `idx_shareholder_action_active` (`action_number_active`)'
  )
);
PREPARE stmt FROM @create_idx_action_active;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
