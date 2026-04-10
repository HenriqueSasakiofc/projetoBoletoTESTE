<?php
require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable('.');
$dotenv->load();
\Config\Database::connect();
echo 'DB OK' . PHP_EOL;

// Check all required tables
$tables = ['customers', 'receivables', 'staging_customers', 'staging_receivables', 'upload_batches', 'outbox_messages', 'notification_templates'];
foreach ($tables as $table) {
    try {
        $count = \Illuminate\Database\Capsule\Manager::table($table)->count();
        echo $table . ': OK (' . $count . ' rows)' . PHP_EOL;
    } catch (\Exception $e) {
        echo $table . ': ERROR - ' . $e->getMessage() . PHP_EOL;
    }
}
