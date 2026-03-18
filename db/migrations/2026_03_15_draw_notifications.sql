-- =========================================================
-- Club Puerto Azul - Delta v24
-- Notificaciones de resultados vía WhatsApp/Botmaker
-- =========================================================

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `draw_notification_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `draw_id` bigint(20) unsigned NOT NULL,
  `participant_id` bigint(20) unsigned DEFAULT NULL,
  `winner_id` bigint(20) unsigned DEFAULT NULL,
  `notification_type` varchar(30) NOT NULL,
  `channel` varchar(30) NOT NULL DEFAULT 'WHATSAPP',
  `template_name` varchar(120) NOT NULL,
  `phone_e164` varchar(25) DEFAULT NULL,
  `payload_json` longtext DEFAULT NULL,
  `provider_response_json` longtext DEFAULT NULL,
  `status` varchar(20) NOT NULL,
  `idempotency_key` varchar(160) NOT NULL,
  `error_code` varchar(80) DEFAULT NULL,
  `error_message` varchar(500) DEFAULT NULL,
  `retry_count` int(11) NOT NULL DEFAULT 0,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_draw_notification_log_idempotency` (`idempotency_key`),
  KEY `idx_draw_notification_draw` (`draw_id`,`notification_type`,`status`),
  KEY `idx_draw_notification_participant` (`participant_id`),
  KEY `idx_draw_notification_winner` (`winner_id`),
  CONSTRAINT `fk_draw_notification_draw` FOREIGN KEY (`draw_id`) REFERENCES `draw` (`id`),
  CONSTRAINT `fk_draw_notification_participant` FOREIGN KEY (`participant_id`) REFERENCES `draw_participant` (`id`),
  CONSTRAINT `fk_draw_notification_winner` FOREIGN KEY (`winner_id`) REFERENCES `draw_winner` (`id`),
  CONSTRAINT `fk_draw_notification_created_by` FOREIGN KEY (`created_by`) REFERENCES `app_user` (`id`),
  CONSTRAINT `fk_draw_notification_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `app_user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
