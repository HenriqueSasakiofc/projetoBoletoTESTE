-- SQL Schema for MySQL
CREATE TABLE `companies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `slug` VARCHAR(120) NOT NULL UNIQUE,
  `legal_name` VARCHAR(255) NOT NULL,
  `trade_name` VARCHAR(255) NOT NULL,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `company_id` INT NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` VARCHAR(50) DEFAULT 'ADMIN',
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE(`company_id`, `email`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`)
);

CREATE TABLE `upload_batches` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `company_id` INT NOT NULL,
  `uploaded_by_user_id` INT NOT NULL,
  `approved_by_user_id` INT DEFAULT NULL,
  `customers_filename` VARCHAR(255) NOT NULL,
  `receivables_filename` VARCHAR(255) NOT NULL,
  `customers_hash` VARCHAR(64) NOT NULL,
  `receivables_hash` VARCHAR(64) NOT NULL,
  `status` VARCHAR(50) DEFAULT 'processing',
  `preview_customers_total` INT DEFAULT 0,
  `preview_receivables_total` INT DEFAULT 0,
  `preview_invalid_customers` INT DEFAULT 0,
  `preview_invalid_receivables` INT DEFAULT 0,
  `preview_pending_links` INT DEFAULT 0,
  `merged_customers_count` INT DEFAULT 0,
  `merged_receivables_count` INT DEFAULT 0,
  `error_message` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`),
  FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`approved_by_user_id`) REFERENCES `users`(`id`)
);

-- Note: Other tables (staging_customers, staging_receivables, customers, receivables, outbox_messages) follow the same pattern based on the previous SQLAlchemy models.
