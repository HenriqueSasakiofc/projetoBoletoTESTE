<?php
// Simulate an upload request to the import endpoint to get the real PHP error
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
\Config\Database::connect();

// Mock a fake tmp file for testing
$testFile = sys_get_temp_dir() . '/test_import.xlsx';

// Check if there's a real xlsx we can use for testing
$testFiles = glob(__DIR__ . '/../*.xlsx') ?: [];
if (empty($testFiles)) {
    // Create a minimal test by checking what ImporterService does with a constructed record
    echo "No xlsx found in project root. Testing processCustomerBatch with mock data...\n";
    
    $records = [
        ['nome' => 'Cliente Teste', 'cpf' => '12345678900', 'email' => 'teste@teste.com', 'telefone' => '11999999999']
    ];
    
    try {
        \App\Services\ImporterService::processCustomerBatch(1, 9990, $records);
        echo "processCustomerBatch: OK\n";
    } catch (\Exception $e) {
        echo "processCustomerBatch ERROR: " . $e->getMessage() . "\n";
    }
    
    $recRecords = [
        ['nome' => 'Cliente Teste', 'valor' => '100,00', 'vencimento' => '30/04/2026', 'numero_titulo' => 'DOC001']
    ];
    
    try {
        \App\Services\ImporterService::processReceivableBatch(1, 9990, $recRecords);
        echo "processReceivableBatch: OK\n";
    } catch (\Exception $e) {
        echo "processReceivableBatch ERROR: " . $e->getMessage() . "\n";
    }

    // Cleanup test data
    \Illuminate\Database\Capsule\Manager::table('staging_customers')->where('upload_batch_id', 9990)->delete();
    \Illuminate\Database\Capsule\Manager::table('staging_receivables')->where('upload_batch_id', 9990)->delete();
    echo "Cleanup done.\n";
} else {
    echo "Found xlsx files: " . implode(', ', $testFiles) . "\n";
}
