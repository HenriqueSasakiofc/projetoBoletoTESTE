<?php
require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(getcwd());
$dotenv->load();
Config\Database::connect();
require 'src/Services/ImporterService.php';

$batch = App\Models\UploadBatch::create([
  'company_id' => 1,
  'uploaded_by_user_id' => 1,
  'customers_filename' => 'debug_clientes.csv',
  'receivables_filename' => 'debug_recebiveis.csv',
  'customers_hash' => str_repeat('1', 64),
  'receivables_hash' => str_repeat('2', 64),
  'status' => 'processing',
]);

$customerRecords = App\Services\ImporterService::readExcelAsRecords('tmp_header_test/clientes_header.csv');
$receivableRecords = App\Services\ImporterService::readExcelAsRecords('tmp_header_test/recebiveis_header.csv');
App\Services\ImporterService::processCustomerBatch(1, $batch->id, $customerRecords);
App\Services\ImporterService::processReceivableBatch(1, $batch->id, $receivableRecords);
$stagingCustomer = App\Models\StagingCustomer::where('upload_batch_id', $batch->id)->first();
$stagingReceivable = App\Models\StagingReceivable::where('upload_batch_id', $batch->id)->first();
echo json_encode([
  'customer_email' => $stagingCustomer?->email_billing,
  'receivable_number' => $stagingReceivable?->receivable_number,
  'receivable_nosso_numero' => $stagingReceivable?->nosso_numero,
  'receivable_email' => $stagingReceivable?->email_billing,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
