<?php
// Fix missing columns in staging_customers and staging_receivables
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
\Config\Database::connect();

$db = \Illuminate\Database\Capsule\Manager::connection();

echo "=== Fixing staging_customers ===\n";
$colsToAdd = [
    "ADD COLUMN IF NOT EXISTS `normalized_name` VARCHAR(255) NULL AFTER `full_name`",
    "ADD COLUMN IF NOT EXISTS `row_number` INT DEFAULT 0 AFTER `upload_batch_id`",
    "ADD COLUMN IF NOT EXISTS `document_number` VARCHAR(20) NULL",
    "ADD COLUMN IF NOT EXISTS `email_billing` VARCHAR(255) NULL",
    "ADD COLUMN IF NOT EXISTS `phone` VARCHAR(20) NULL",
    "ADD COLUMN IF NOT EXISTS `raw_payload` JSON NULL",
    "ADD COLUMN IF NOT EXISTS `validation_status` VARCHAR(20) DEFAULT 'valid'",
    "ADD COLUMN IF NOT EXISTS `error_message` TEXT NULL",
];
foreach ($colsToAdd as $alter) {
    try {
        $db->statement("ALTER TABLE staging_customers $alter");
        echo "  OK: $alter\n";
    } catch (\Exception $e) {
        echo "  SKIP (already exists?): " . $e->getMessage() . "\n";
    }
}

echo "\n=== Fixing staging_receivables ===\n";
$colsToAdd2 = [
    "ADD COLUMN IF NOT EXISTS `normalized_customer_name` VARCHAR(255) NULL AFTER `customer_name`",
    "ADD COLUMN IF NOT EXISTS `row_number` INT DEFAULT 0 AFTER `upload_batch_id`",
    "ADD COLUMN IF NOT EXISTS `customer_document_number` VARCHAR(20) NULL",
    "ADD COLUMN IF NOT EXISTS `receivable_number` VARCHAR(50) NULL",
    "ADD COLUMN IF NOT EXISTS `nosso_numero` VARCHAR(50) NULL",
    "ADD COLUMN IF NOT EXISTS `due_date` DATE NULL",
    "ADD COLUMN IF NOT EXISTS `amount_total` DECIMAL(15,2) NULL",
    "ADD COLUMN IF NOT EXISTS `raw_payload` JSON NULL",
    "ADD COLUMN IF NOT EXISTS `validation_status` VARCHAR(20) DEFAULT 'valid'",
    "ADD COLUMN IF NOT EXISTS `error_message` TEXT NULL",
];
foreach ($colsToAdd2 as $alter) {
    try {
        $db->statement("ALTER TABLE staging_receivables $alter");
        echo "  OK: $alter\n";
    } catch (\Exception $e) {
        echo "  SKIP (already exists?): " . $e->getMessage() . "\n";
    }
}

// Also check outbox_messages has required columns
echo "\n=== Verifying outbox_messages ===\n";
try {
    $cols = $db->select("DESCRIBE outbox_messages");
    foreach ($cols as $col) {
        echo "  column: " . $col->Field . " (" . $col->Type . ")\n";
    }
} catch (\Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
