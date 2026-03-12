-- =========================================================
-- Club Puerto Azul - Delta v20
-- Fortalecimiento de registro con accionistas + documento
-- Fecha: 2026-03-12
-- Stack: PHP + MySQL + React
-- =========================================================

START TRANSACTION;

-- ---------------------------------------------------------
-- 1) Participantes: agregar documento del participante
-- ---------------------------------------------------------
ALTER TABLE `draw_participant`
  ADD COLUMN `document_type` enum('V','E','J') DEFAULT NULL AFTER `phone_e164`,
  ADD COLUMN `document_number` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `document_type`,
  ADD COLUMN `document_key` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `document_number`;

ALTER TABLE `draw_participant`
  ADD KEY `idx_draw_participant_document_key` (`document_key`),
  ADD KEY `idx_draw_participant_draw_action_status` (`draw_id`,`action_number`,`status`,`inactive_at`),
  ADD KEY `idx_draw_participant_scope_action_status` (`participation_scope_id`,`action_number`,`status`,`inactive_at`);

-- ---------------------------------------------------------
-- 2) Accionistas vigentes + historial por versionado lógico
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `shareholder` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `action_number` smallint(5) UNSIGNED NOT NULL,
  `document_type` enum('V','E','J') COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_e164` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_file_import_id` bigint(20) UNSIGNED DEFAULT NULL,
  `active_from` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `inactive_at` datetime DEFAULT NULL,
  `action_number_active` smallint(5) UNSIGNED GENERATED ALWAYS AS ((case when isnull(`inactive_at`) then `action_number` else NULL end)) STORED,
  `document_key_active` varchar(40) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS ((case when isnull(`inactive_at`) then `document_key` else NULL end)) STORED,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shareholder_action_active` (`action_number_active`),
  UNIQUE KEY `uq_shareholder_document_active` (`document_key_active`),
  KEY `idx_shareholder_action` (`action_number`),
  KEY `idx_shareholder_document_key` (`document_key`),
  KEY `idx_shareholder_name` (`first_name`,`last_name`),
  KEY `idx_shareholder_import` (`source_file_import_id`),
  KEY `idx_shareholder_active_window` (`active_from`,`inactive_at`),
  KEY `fk_shareholder_created_by` (`created_by`),
  KEY `fk_shareholder_updated_by` (`updated_by`),
  CONSTRAINT `fk_shareholder_source_file_import` FOREIGN KEY (`source_file_import_id`) REFERENCES `file_import` (`id`),
  CONSTRAINT `fk_shareholder_created_by` FOREIGN KEY (`created_by`) REFERENCES `app_user` (`id`),
  CONSTRAINT `fk_shareholder_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `app_user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 3) Trazabilidad de filas de importación de accionistas
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `shareholder_import_row` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_import_id` bigint(20) UNSIGNED NOT NULL,
  `row_number` int(11) NOT NULL,
  `action_number` smallint(5) UNSIGNED DEFAULT NULL,
  `document_type` enum('V','E','J') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document_number` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document_key` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_e164` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operation` enum('INSERT','UPDATE','NO_CHANGE') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result_status` enum('OK','ERROR','SKIPPED') COLLATE utf8mb4_unicode_ci NOT NULL,
  `error_message` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active_from` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `inactive_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_shareholder_import_row_import` (`file_import_id`),
  KEY `idx_shareholder_import_row_action` (`action_number`),
  KEY `idx_shareholder_import_row_document_key` (`document_key`),
  KEY `fk_shareholder_import_row_created_by` (`created_by`),
  KEY `fk_shareholder_import_row_updated_by` (`updated_by`),
  CONSTRAINT `fk_shareholder_import_row_import` FOREIGN KEY (`file_import_id`) REFERENCES `file_import` (`id`),
  CONSTRAINT `fk_shareholder_import_row_created_by` FOREIGN KEY (`created_by`) REFERENCES `app_user` (`id`),
  CONSTRAINT `fk_shareholder_import_row_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `app_user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
