<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Models\Company;
use App\Services\AutomaticNotificationService;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
\Config\Database::connect();

$connection = Capsule::connection();
$databaseName = $connection->getDatabaseName();

$columnExists = function (string $table, string $column) use ($connection, $databaseName): bool {
    $rows = $connection->select(
        'SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ? LIMIT 1',
        [$databaseName, $table, $column]
    );

    return !empty($rows);
};

$indexExists = function (string $table, string $index) use ($connection, $databaseName): bool {
    $rows = $connection->select(
        'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
        [$databaseName, $table, $index]
    );

    return !empty($rows);
};

$connection->statement(
    "CREATE TABLE IF NOT EXISTS `notification_templates` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

if (!$columnExists('outbox_messages', 'notification_event')) {
    $connection->statement(
        "ALTER TABLE `outbox_messages`
            ADD COLUMN `notification_event` VARCHAR(50) DEFAULT NULL AFTER `message_kind`"
    );
}

if (!$columnExists('outbox_messages', 'scheduled_for_date')) {
    $connection->statement(
        "ALTER TABLE `outbox_messages`
            ADD COLUMN `scheduled_for_date` DATE DEFAULT NULL AFTER `notification_event`"
    );
}

if (!$indexExists('outbox_messages', 'idx_outbox_messages_event_schedule')) {
    $connection->statement(
        "ALTER TABLE `outbox_messages`
            ADD INDEX `idx_outbox_messages_event_schedule` (`company_id`, `notification_event`, `scheduled_for_date`)"
    );
}

if (!$indexExists('outbox_messages', 'idx_outbox_messages_receivable_event')) {
    $connection->statement(
        "ALTER TABLE `outbox_messages`
            ADD INDEX `idx_outbox_messages_receivable_event` (`company_id`, `receivable_id`, `notification_event`)"
    );
}

$companies = Company::where('is_active', 1)->get();
foreach ($companies as $company) {
    AutomaticNotificationService::ensureTemplates((int) $company->id);
}

echo json_encode([
    'status' => 'ok',
    'notification_templates_seeded_for_companies' => $companies->count(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
