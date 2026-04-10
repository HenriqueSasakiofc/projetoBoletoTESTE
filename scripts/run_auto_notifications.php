<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\AutomaticNotificationService;
use Dotenv\Dotenv;

$options = getopt('', [
    'company-id::',
    'date::',
    'dry-run',
    'dispatch-limit::',
    'skip-dispatch',
    'help',
]);

if (isset($options['help'])) {
    echo "Uso:\n";
    echo "  php scripts/run_auto_notifications.php [--company-id=1] [--date=2026-04-10] [--dry-run] [--dispatch-limit=200] [--skip-dispatch]\n\n";
    echo "Descricao:\n";
    echo "  Agenda os eventos automaticos de cobranca com base na data de vencimento e, opcionalmente,\n";
    echo "  tenta enviar os itens automaticos pendentes/erro da fila outbox.\n";
    exit(0);
}

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
\Config\Database::connect();

$companyId = isset($options['company-id']) ? (int) $options['company-id'] : null;
$referenceDate = isset($options['date']) && $options['date'] !== false ? (string) $options['date'] : null;
$dryRun = isset($options['dry-run']);
$dispatchLimit = isset($options['dispatch-limit']) ? max(0, (int) $options['dispatch-limit']) : 200;

if (isset($options['skip-dispatch'])) {
    $dispatchLimit = 0;
}

if ($companyId) {
    $result = AutomaticNotificationService::runForCompany($companyId, $referenceDate, $dryRun, $dispatchLimit);
} else {
    $result = AutomaticNotificationService::runForAllCompanies($referenceDate, $dryRun, $dispatchLimit);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
