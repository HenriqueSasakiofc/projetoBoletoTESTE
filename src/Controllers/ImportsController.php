<?php
namespace App\Controllers;

use App\Services\ImporterService;
use App\Services\NotifierService;
use App\Models\Receivable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class ImportsController {
    private function getTokenPayload(): ?object {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) return null;
        $token = substr($authHeader, 7);
        try {
            $secretKey = $_ENV['SECRET_KEY'] ?? 'fallback_secret_key_change_me';
            return JWT::decode($token, new Key($secretKey, 'HS256'));
        } catch (\Exception $e) {
            return null;
        }
    }

    public function upload() {
        header('Content-Type: application/json');

        $payload = $this->getTokenPayload();
        if (!$payload) {
            // Fallback: use first user's company if no valid token (dev mode)
            $firstUser = \App\Models\User::first();
            $companyId = $firstUser ? $firstUser->company_id : 1;
            $userId    = $firstUser ? $firstUser->id : 1;
        } else {
            $companyId = $payload->company_id;
            $userId    = (int) $payload->sub;
        }

        // Verifica se ambas as planilhas vieram no payload
        if (!isset($_FILES['customers_upload']) || !isset($_FILES['receivables_upload'])) {
            http_response_code(400);
            echo json_encode(['error' => 'É necessário enviar a planilha de clientes e a de contas a receber.']);
            return;
        }

        // Validate files uploaded OK
        if ($_FILES['customers_upload']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'Erro no envio do arquivo de clientes (código ' . $_FILES['customers_upload']['error'] . ')']);
            return;
        }
        if ($_FILES['receivables_upload']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'Erro no envio do arquivo de cobranças (código ' . $_FILES['receivables_upload']['error'] . ')']);
            return;
        }

        try {
            // Use a unique batchId that stays consistent for the full pipeline
            $batchId = time() . rand(100, 999);

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
                            'company_id'      => $companyId,
                            'full_name'       => $stagingCust->full_name,
                            'normalized_name' => $stagingCust->normalized_name,
                            'document_number' => $stagingCust->document_number,
                            'email_billing'   => $stagingCust->email_billing,
                            'phone'           => $stagingCust->phone,
                            'is_active'       => true
                        ]);
                    } else {
                        $customer = \App\Models\Customer::create([
                            'company_id'      => $companyId,
                            'full_name'       => $stagingRec->customer_name,
                            'normalized_name' => $stagingRec->normalized_customer_name,
                            'is_active'       => true
                        ]);
                    }
                }

                // Cria a Conta a Receber Oficial
                $receivable = Receivable::create([
                    'company_id'                => $companyId,
                    'customer_id'               => $customer->id,
                    'upload_batch_id'           => $batchId,
                    'receivable_number'         => $stagingRec->receivable_number,
                    'nosso_numero'              => $stagingRec->nosso_numero,
                    'due_date'                  => $stagingRec->due_date,
                    'amount_total'              => $stagingRec->amount_total,
                    'balance_amount'            => $stagingRec->amount_total,
                    'status'                    => 'EM_ABERTO',
                    'snapshot_customer_name'    => $customer->full_name,
                    'snapshot_customer_document'=> $customer->document_number,
                    'snapshot_email_billing'    => $customer->email_billing,
                    'is_active'                 => true
                ]);

                try {
                    NotifierService::queueStandardMessage($companyId, $receivable, $userId);
                    $queuedCount++;
                } catch (\Exception $e) {
                    continue;
                }
            }

            // 4. DISPARO IMEDIATO DOS E-MAILS DA PLANILHA IMPORTADA
            $dispatchResult = NotifierService::dispatchPendingOutbox($companyId, 500);

            // Cleanup staging data
            \App\Models\StagingCustomer::where('upload_batch_id', $batchId)->delete();
            \App\Models\StagingReceivable::where('upload_batch_id', $batchId)->delete();

            $customersCount = \App\Models\Customer::where('company_id', $companyId)->count();

            echo json_encode([
                'status'            => 'success',
                'message'           => 'Lote processado com sucesso!',
                'customers_parsed'  => count($customerRecords),
                'receivables_parsed'=> count($receivableRecords),
                'emails_queued'     => $queuedCount,
                'emails_sent_now'   => $dispatchResult['sent'],
                'emails_failed'     => $dispatchResult['errors'],
                'total_customers'   => $customersCount,
            ]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro fatal no processamento ou envio: ' . $e->getMessage()]);
        }
    }
}
