-- Full MySQL schema for Projeto Boleto
-- Recommended database defaults:
--   CHARACTER SET utf8mb4
--   COLLATE utf8mb4_unicode_ci

CREATE TABLE IF NOT EXISTS `companies` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(120) NOT NULL,
  `legal_name` VARCHAR(255) NOT NULL,
  `trade_name` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_companies_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` VARCHAR(50) NOT NULL DEFAULT 'ADMIN',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_company_email` (`company_id`, `email`),
  KEY `idx_users_company_id` (`company_id`),
  CONSTRAINT `fk_users_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `upload_batches` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `uploaded_by_user_id` INT UNSIGNED NOT NULL,
  `approved_by_user_id` INT UNSIGNED DEFAULT NULL,
  `customers_filename` VARCHAR(255) NOT NULL,
  `receivables_filename` VARCHAR(255) NOT NULL,
  `customers_hash` VARCHAR(64) NOT NULL,
  `receivables_hash` VARCHAR(64) NOT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'processing',
  `preview_customers_total` INT NOT NULL DEFAULT 0,
  `preview_receivables_total` INT NOT NULL DEFAULT 0,
  `preview_invalid_customers` INT NOT NULL DEFAULT 0,
  `preview_invalid_receivables` INT NOT NULL DEFAULT 0,
  `preview_pending_links` INT NOT NULL DEFAULT 0,
  `merged_customers_count` INT NOT NULL DEFAULT 0,
  `merged_receivables_count` INT NOT NULL DEFAULT 0,
  `error_message` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_upload_batches_company_id` (`company_id`),
  KEY `idx_upload_batches_uploaded_by` (`uploaded_by_user_id`),
  KEY `idx_upload_batches_approved_by` (`approved_by_user_id`),
  KEY `idx_upload_batches_status` (`status`),
  CONSTRAINT `fk_upload_batches_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_upload_batches_uploaded_by`
    FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `fk_upload_batches_approved_by`
    FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staging_customers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `upload_batch_id` INT UNSIGNED NOT NULL,
  `row_number` INT NOT NULL,
  `external_code` VARCHAR(120) DEFAULT NULL,
  `full_name` VARCHAR(255) NOT NULL,
  `normalized_name` VARCHAR(255) NOT NULL,
  `document_number` VARCHAR(20) DEFAULT NULL,
  `email_billing` VARCHAR(255) DEFAULT NULL,
  `email_financial` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(40) DEFAULT NULL,
  `other_contacts` TEXT DEFAULT NULL,
  `raw_payload` JSON DEFAULT NULL,
  `validation_status` VARCHAR(20) NOT NULL DEFAULT 'valid',
  `validation_errors` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_staging_customers_company_id` (`company_id`),
  KEY `idx_staging_customers_upload_batch_id` (`upload_batch_id`),
  KEY `idx_staging_customers_external_code` (`external_code`),
  KEY `idx_staging_customers_normalized_name` (`normalized_name`),
  KEY `idx_staging_customers_document_number` (`document_number`),
  CONSTRAINT `fk_staging_customers_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_staging_customers_batch`
    FOREIGN KEY (`upload_batch_id`) REFERENCES `upload_batches` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `external_code` VARCHAR(120) DEFAULT NULL,
  `full_name` VARCHAR(255) NOT NULL,
  `normalized_name` VARCHAR(255) NOT NULL,
  `document_number` VARCHAR(20) DEFAULT NULL,
  `email_billing` VARCHAR(255) DEFAULT NULL,
  `email_financial` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(40) DEFAULT NULL,
  `other_contacts` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customers_company_external_code` (`company_id`, `external_code`),
  KEY `idx_customers_company_id` (`company_id`),
  KEY `idx_customers_full_name` (`full_name`),
  KEY `idx_customers_normalized_name` (`normalized_name`),
  KEY `idx_customers_document_number` (`document_number`),
  CONSTRAINT `fk_customers_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staging_receivables` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `upload_batch_id` INT UNSIGNED NOT NULL,
  `row_number` INT NOT NULL,
  `customer_external_code` VARCHAR(120) DEFAULT NULL,
  `customer_name` VARCHAR(255) NOT NULL,
  `normalized_customer_name` VARCHAR(255) NOT NULL,
  `customer_document_number` VARCHAR(20) DEFAULT NULL,
  `receivable_number` VARCHAR(120) DEFAULT NULL,
  `nosso_numero` VARCHAR(120) DEFAULT NULL,
  `charge_type` VARCHAR(120) DEFAULT NULL,
  `issue_date` DATE DEFAULT NULL,
  `due_date` DATE DEFAULT NULL,
  `amount_total` DECIMAL(14,2) DEFAULT NULL,
  `balance_amount` DECIMAL(14,2) DEFAULT NULL,
  `balance_without_interest` DECIMAL(14,2) DEFAULT NULL,
  `status_raw` VARCHAR(120) DEFAULT NULL,
  `email_billing` VARCHAR(255) DEFAULT NULL,
  `raw_payload` JSON DEFAULT NULL,
  `validation_status` VARCHAR(20) NOT NULL DEFAULT 'valid',
  `validation_errors` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_staging_receivables_company_id` (`company_id`),
  KEY `idx_staging_receivables_upload_batch_id` (`upload_batch_id`),
  KEY `idx_staging_receivables_customer_external_code` (`customer_external_code`),
  KEY `idx_staging_receivables_normalized_customer_name` (`normalized_customer_name`),
  KEY `idx_staging_receivables_customer_document_number` (`customer_document_number`),
  KEY `idx_staging_receivables_receivable_number` (`receivable_number`),
  KEY `idx_staging_receivables_nosso_numero` (`nosso_numero`),
  KEY `idx_staging_receivables_due_date` (`due_date`),
  CONSTRAINT `fk_staging_receivables_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_staging_receivables_batch`
    FOREIGN KEY (`upload_batch_id`) REFERENCES `upload_batches` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `receivables` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `upload_batch_id` INT UNSIGNED DEFAULT NULL,
  `receivable_number` VARCHAR(120) DEFAULT NULL,
  `nosso_numero` VARCHAR(120) DEFAULT NULL,
  `charge_type` VARCHAR(120) DEFAULT NULL,
  `issue_date` DATE DEFAULT NULL,
  `due_date` DATE DEFAULT NULL,
  `amount_total` DECIMAL(14,2) DEFAULT NULL,
  `balance_amount` DECIMAL(14,2) DEFAULT NULL,
  `balance_without_interest` DECIMAL(14,2) DEFAULT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'EM_ABERTO',
  `snapshot_customer_name` VARCHAR(255) DEFAULT NULL,
  `snapshot_customer_document` VARCHAR(20) DEFAULT NULL,
  `snapshot_email_billing` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_standard_message_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_receivables_company_id` (`company_id`),
  KEY `idx_receivables_customer_id` (`customer_id`),
  KEY `idx_receivables_upload_batch_id` (`upload_batch_id`),
  KEY `idx_receivables_receivable_number` (`receivable_number`),
  KEY `idx_receivables_nosso_numero` (`nosso_numero`),
  KEY `idx_receivables_due_date` (`due_date`),
  KEY `idx_receivables_status` (`status`),
  CONSTRAINT `fk_receivables_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_receivables_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_receivables_batch`
    FOREIGN KEY (`upload_batch_id`) REFERENCES `upload_batches` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_link_pendings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `upload_batch_id` INT UNSIGNED NOT NULL,
  `staging_receivable_id` INT UNSIGNED NOT NULL,
  `suggested_customer_id` INT UNSIGNED DEFAULT NULL,
  `resolved_customer_id` INT UNSIGNED DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'open',
  `note` TEXT DEFAULT NULL,
  `resolved_by_user_id` INT UNSIGNED DEFAULT NULL,
  `resolved_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customer_link_pendings_company_id` (`company_id`),
  KEY `idx_customer_link_pendings_upload_batch_id` (`upload_batch_id`),
  KEY `idx_customer_link_pendings_staging_receivable_id` (`staging_receivable_id`),
  KEY `idx_customer_link_pendings_suggested_customer_id` (`suggested_customer_id`),
  KEY `idx_customer_link_pendings_resolved_customer_id` (`resolved_customer_id`),
  CONSTRAINT `fk_customer_link_pendings_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_customer_link_pendings_batch`
    FOREIGN KEY (`upload_batch_id`) REFERENCES `upload_batches` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_customer_link_pendings_staging_receivable`
    FOREIGN KEY (`staging_receivable_id`) REFERENCES `staging_receivables` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_customer_link_pendings_suggested_customer`
    FOREIGN KEY (`suggested_customer_id`) REFERENCES `customers` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_customer_link_pendings_resolved_customer`
    FOREIGN KEY (`resolved_customer_id`) REFERENCES `customers` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_customer_link_pendings_resolved_by_user`
    FOREIGN KEY (`resolved_by_user_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_message_templates_company_id` (`company_id`),
  CONSTRAINT `fk_message_templates_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notification_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `event_code` VARCHAR(50) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_templates_company_event` (`company_id`, `event_code`),
  CONSTRAINT `fk_notification_templates_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `manual_messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `created_by_user_id` INT UNSIGNED NOT NULL,
  `recipient_email` VARCHAR(255) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `preview_hash` VARCHAR(64) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_manual_messages_company_id` (`company_id`),
  KEY `idx_manual_messages_customer_id` (`customer_id`),
  KEY `idx_manual_messages_created_by_user_id` (`created_by_user_id`),
  CONSTRAINT `fk_manual_messages_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_manual_messages_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_manual_messages_created_by_user`
    FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `outbox_messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `receivable_id` INT UNSIGNED DEFAULT NULL,
  `customer_id` INT UNSIGNED DEFAULT NULL,
  `created_by_user_id` INT UNSIGNED DEFAULT NULL,
  `message_kind` VARCHAR(50) NOT NULL,
  `notification_event` VARCHAR(50) DEFAULT NULL,
  `scheduled_for_date` DATE DEFAULT NULL,
  `recipient_email` VARCHAR(255) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `dedupe_key` VARCHAR(255) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `error_message` TEXT DEFAULT NULL,
  `sent_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outbox_messages_company_dedupe_key` (`company_id`, `dedupe_key`),
  KEY `idx_outbox_messages_company_id` (`company_id`),
  KEY `idx_outbox_messages_receivable_id` (`receivable_id`),
  KEY `idx_outbox_messages_customer_id` (`customer_id`),
  KEY `idx_outbox_messages_status` (`status`),
  KEY `idx_outbox_messages_created_at` (`created_at`),
  KEY `idx_outbox_messages_event_schedule` (`company_id`, `notification_event`, `scheduled_for_date`),
  KEY `idx_outbox_messages_receivable_event` (`company_id`, `receivable_id`, `notification_event`),
  CONSTRAINT `fk_outbox_messages_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_outbox_messages_receivable`
    FOREIGN KEY (`receivable_id`) REFERENCES `receivables` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_outbox_messages_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_outbox_messages_created_by_user`
    FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `receivable_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `receivable_id` INT UNSIGNED NOT NULL,
  `changed_by_user_id` INT UNSIGNED DEFAULT NULL,
  `old_status` VARCHAR(50) DEFAULT NULL,
  `new_status` VARCHAR(50) DEFAULT NULL,
  `note` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_receivable_history_company_id` (`company_id`),
  KEY `idx_receivable_history_receivable_id` (`receivable_id`),
  CONSTRAINT `fk_receivable_history_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_receivable_history_receivable`
    FOREIGN KEY (`receivable_id`) REFERENCES `receivables` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_receivable_history_changed_by_user`
    FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED DEFAULT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `entity` VARCHAR(100) NOT NULL,
  `entity_id` VARCHAR(100) DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_company_id` (`company_id`),
  KEY `idx_audit_logs_user_id` (`user_id`),
  KEY `idx_audit_logs_entity` (`entity`),
  KEY `idx_audit_logs_action` (`action`),
  CONSTRAINT `fk_audit_logs_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_audit_logs_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
