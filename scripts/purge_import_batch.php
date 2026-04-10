<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Models\UploadBatch;
use App\Services\UploadBatchMaintenanceService;
use Dotenv\Dotenv;

$options = getopt('', ['batch-id:', 'dry-run', 'help']);

if (isset($options['help']) || !isset($options['batch-id'])) {
    echo "Uso:\n";
    echo "  php scripts/purge_import_batch.php --batch-id=15 [--dry-run]\n\n";
    echo "Descricao:\n";
    echo "  Localiza um lote pelo ID informado, encontra todos os batches da mesma empresa\n";
    echo "  com o mesmo par de hashes, remove cobrancas e mensagens padrao vinculadas e\n";
    echo "  marca os batches como 'purged' para permitir reimportacao futura.\n";
    exit(isset($options['help']) ? 0 : 1);
}

$batchId = (int) $options['batch-id'];
$dryRun = isset($options['dry-run']);

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
\Config\Database::connect();

$seedBatch = UploadBatch::find($batchId);
if (!$seedBatch) {
    fwrite(STDERR, "Lote {$batchId} nao encontrado.\n");
    exit(1);
}

$summary = UploadBatchMaintenanceService::buildPurgeSummary($seedBatch);
$summary['dry_run'] = $dryRun;

if ($dryRun) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$result = UploadBatchMaintenanceService::purgeMatchingBatches($seedBatch);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
