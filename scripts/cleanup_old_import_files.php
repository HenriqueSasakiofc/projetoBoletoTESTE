<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\UploadBatchMaintenanceService;
use Dotenv\Dotenv;

$options = getopt('', ['days::', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo "Uso:\n";
    echo "  php scripts/cleanup_old_import_files.php [--days=30] [--dry-run]\n\n";
    echo "Descricao:\n";
    echo "  Remove somente os arquivos fisicos das planilhas antigas em storage/imports.\n";
    echo "  Os dados ja importados no banco, clientes, cobrancas e historico de lotes sao mantidos.\n";
    echo "  Se um arquivo antigo ainda for referenciado por um lote recente, ele nao sera removido.\n";
    exit(0);
}

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
\Config\Database::connect();

$days = isset($options['days']) && $options['days'] !== false
    ? max(1, (int) $options['days'])
    : null;

$result = UploadBatchMaintenanceService::cleanupStoredImportFiles($days, isset($options['dry-run']));

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
