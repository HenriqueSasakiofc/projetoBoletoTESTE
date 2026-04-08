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

            // 3. TRANSFERIR DO STAGING PARA O BANCO OFICIAL E ENFILEIRAR MENSAGENS
            $queuedCount = 0;
            $receivablesImportedThisBatch = \App\Models\StagingReceivable::where('upload_batch_id', $batchId)->get();

            foreach ($receivablesImportedThisBatch as $stagingRec) {
                $normName = $stagingRec->normalized_customer_name;
                
                // Primeiro procura na base oficial
                $customer = \App\Models\Customer::where('company_id', $companyId)
                                ->where('normalized_name', $normName)
                                ->first();

                if (!$customer) {
                    // Tenta achar no staging importado agora
                    $stagingCust = \App\Models\StagingCustomer::where('upload_batch_id', $batchId)
                                        ->where('normalized_name', $normName)
                                        ->first();
                    
                    if ($stagingCust) {
                        $customer = \App\Models\Customer::create([
                            'company_id' => $companyId,
                            'full_name' => $stagingCust->full_name,
                            'normalized_name' => $stagingCust->normalized_name,
                            'document_number' => $stagingCust->document_number,
                            'email_billing' => $stagingCust->email_billing,
                            'phone' => $stagingCust->phone,
                            'is_active' => true
                        ]);
                    } else {
                        // Se não encontrou, cria um cliente genérico com o que temos
                        $customer = \App\Models\Customer::create([
                            'company_id' => $companyId,
                            'full_name' => $stagingRec->customer_name,
                            'normalized_name' => $stagingRec->normalized_customer_name,
                            'is_active' => true
                        ]);
                    }
                }

                // Cria a Conta a Receber Oficial
                $receivable = Receivable::create([
                    'company_id' => $companyId,
                    'customer_id' => $customer->id,
                    'upload_batch_id' => $batchId,
                    'receivable_number' => $stagingRec->receivable_number,
                    'nosso_numero' => $stagingRec->nosso_numero,
                    'due_date' => $stagingRec->due_date,
                    'amount_total' => $stagingRec->amount_total,
                    'balance_amount' => $stagingRec->amount_total,
                    'status' => 'EM_ABERTO',
                    'snapshot_customer_name' => $customer->full_name,
                    'snapshot_customer_document' => $customer->document_number,
                    'snapshot_email_billing' => $customer->email_billing,
                    'is_active' => true
                ]);

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
