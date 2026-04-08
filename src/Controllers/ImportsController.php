<?php
namespace App\Controllers;

use App\Services\ImporterService;
use App\Services\NotifierService;
use App\Models\Receivable;

class ImportsController {
    public function upload() {
        header('Content-Type: application/json');
        
        // Verifica se ambas as planilhas vieram no payload
        if (!isset($_FILES['customers_upload']) || !isset($_FILES['receivables_upload'])) {
            http_response_code(400);
            echo json_encode(['error' => 'É necessário enviar a planilha de clientes e a de contas a receber.']);
            return;
        }

        try {
            $companyId = 1; // Obtido via token autenticado (JWT)
            $userId = 1; // Operador da importação
            $batchId = rand(1000, 9999); 
            
            // 1. IMPORTAÇÃO DOS CLIENTES
            $customersFile = $_FILES['customers_upload']['tmp_name'];
            $customerRecords = ImporterService::readExcelAsRecords($customersFile);
            ImporterService::processCustomerBatch($companyId, $batchId, $customerRecords);
            
            // 2. IMPORTAÇÃO DOS RECEBÍVEIS (CONTAS)
            $receivablesFile = $_FILES['receivables_upload']['tmp_name'];
            $receivableRecords = ImporterService::readExcelAsRecords($receivablesFile);
            ImporterService::processReceivableBatch($companyId, $batchId, $receivableRecords);

            // 3. (SIMULAÇÃO) TRANSFERIR DO STAGING PARA O BANCO OFICIAL E ENFILEIRAR MENSAGENS
            // Aqui você aprovaria o lote, mas como deseja envio instantâneo mediante a planilha:
            $queuedCount = 0;
            $receivablesImportedThisBatch = Receivable::where('upload_batch_id', $batchId)->get();

            foreach ($receivablesImportedThisBatch as $receivable) {
                try {
                    // Coloca na Caixa de Saída garantindo a trava de 24h
                    NotifierService::queueStandardMessage($companyId, $receivable, $userId);
                    $queuedCount++;
                } catch (\Exception $e) {
                    // A cobrança já foi avisada nas últimas 24 hrs. Ignorar o disparo duplicado.
                    continue;
                }
            }

            // 4. DISPARO IMEDIATO DOS E-MAILS DA PLANILHA IMPORTADA
            $dispatchResult = NotifierService::dispatchPendingOutbox($companyId, 500); // Tenta disparar o bolo inteiro da vez
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Fluxo de Lote Processado Completamente!',
                'customers_parsed' => count($customerRecords),
                'receivables_parsed' => count($receivableRecords),
                'emails_queued' => $queuedCount,
                'emails_sent_now' => $dispatchResult['sent'],
                'emails_failed' => $dispatchResult['errors']
            ]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro fatal no processamento ou envio: ' . $e->getMessage()]);
        }
    }
}
