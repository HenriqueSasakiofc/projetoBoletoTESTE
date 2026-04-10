<?php
namespace App\Controllers;

use App\Models\Customer;
use App\Models\Receivable;
use App\Models\StagingCustomer;
use App\Models\StagingReceivable;
use App\Models\UploadBatch;
use App\Services\ImporterService;
use App\Services\NotifierService;
use App\Support\Auth;

class ImportsApiController {
    private function getAuthedUser() {
        return Auth::userFromRequest();
    }

    private function normalizeStatus(?string $rawStatus): string {
        $status = strtoupper(trim((string) $rawStatus));

        if ($status === '') {
            return 'EM_ABERTO';
        }

        if (str_contains($status, 'PAG')) {
            return 'PAGO';
        }

        if (str_contains($status, 'CANCEL')) {
            return 'CANCELADO';
        }

        if (str_contains($status, 'BAIX')) {
            return 'BAIXADO';
        }

        return 'EM_ABERTO';
    }

    public function upload() {
        header('Content-Type: application/json');

        $user = $this->getAuthedUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        if (!isset($_FILES['customers_upload']) || !isset($_FILES['receivables_upload'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Envie a planilha de clientes e a de cobrancas.']);
            return;
        }

        if ($_FILES['customers_upload']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'Erro no envio do arquivo de clientes.']);
            return;
        }

        if ($_FILES['receivables_upload']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'Erro no envio do arquivo de cobrancas.']);
            return;
        }

        $customersFile = $_FILES['customers_upload']['tmp_name'];
        $receivablesFile = $_FILES['receivables_upload']['tmp_name'];

        $batch = UploadBatch::create([
            'company_id' => $user->company_id,
            'uploaded_by_user_id' => $user->id,
            'customers_filename' => $_FILES['customers_upload']['name'] ?? 'clientes.xlsx',
            'receivables_filename' => $_FILES['receivables_upload']['name'] ?? 'cobrancas.xlsx',
            'customers_hash' => hash_file('sha256', $customersFile),
            'receivables_hash' => hash_file('sha256', $receivablesFile),
            'status' => 'processing',
        ]);

        try {
            $customerRecords = ImporterService::readExcelAsRecords($customersFile);
            $receivableRecords = ImporterService::readExcelAsRecords($receivablesFile);

            $customerStats = ImporterService::processCustomerBatch($user->company_id, $batch->id, $customerRecords);
            $receivableStats = ImporterService::processReceivableBatch($user->company_id, $batch->id, $receivableRecords);

            $stagedCustomersCount = StagingCustomer::where('upload_batch_id', $batch->id)
                ->where('validation_status', 'valid')
                ->count();
            $stagedReceivables = StagingReceivable::where('upload_batch_id', $batch->id)
                ->where('validation_status', 'valid')
                ->get();

            if ($stagedCustomersCount === 0 || $stagedReceivables->isEmpty()) {
                $batch->status = 'error';
                $batch->preview_customers_total = $customerStats['total'];
                $batch->preview_receivables_total = $receivableStats['total'];
                $batch->preview_invalid_customers = $customerStats['invalid'];
                $batch->preview_invalid_receivables = $receivableStats['invalid'];
                $batch->error_message = 'Nenhuma linha valida foi encontrada em uma ou ambas as planilhas.';
                $batch->save();

                http_response_code(422);
                echo json_encode([
                    'error' => 'Nenhuma linha valida foi encontrada em uma ou ambas as planilhas. Verifique o cabecalho e os nomes das colunas.',
                    'batch_id' => (int) $batch->id,
                    'customers_total_rows' => $customerStats['total'],
                    'customers_valid_rows' => $customerStats['accepted'],
                    'customers_invalid_rows' => $customerStats['invalid'],
                    'receivables_total_rows' => $receivableStats['total'],
                    'receivables_valid_rows' => $receivableStats['accepted'],
                    'receivables_invalid_rows' => $receivableStats['invalid'],
                ]);
                return;
            }

            $createdCustomers = 0;
            $createdReceivables = 0;
            $queuedCount = 0;

            foreach ($stagedReceivables as $stagingRec) {
                $normName = $stagingRec->normalized_customer_name;

                $customer = Customer::where('company_id', $user->company_id)
                    ->where('normalized_name', $normName)
                    ->first();

                if (!$customer) {
                    $stagingCust = StagingCustomer::where('upload_batch_id', $batch->id)
                        ->where('normalized_name', $normName)
                        ->first();

                    if ($stagingCust) {
                        $customer = Customer::create([
                            'company_id' => $user->company_id,
                            'external_code' => $stagingCust->external_code,
                            'full_name' => $stagingCust->full_name,
                            'normalized_name' => $stagingCust->normalized_name,
                            'document_number' => $stagingCust->document_number,
                            'email_billing' => $stagingCust->email_billing,
                            'email_financial' => $stagingCust->email_financial,
                            'phone' => $stagingCust->phone,
                            'other_contacts' => $stagingCust->other_contacts,
                            'is_active' => true,
                        ]);
                    } else {
                        $customer = Customer::create([
                            'company_id' => $user->company_id,
                            'full_name' => $stagingRec->customer_name,
                            'normalized_name' => $stagingRec->normalized_customer_name,
                            'document_number' => $stagingRec->customer_document_number,
                            'email_billing' => $stagingRec->email_billing,
                            'is_active' => true,
                        ]);
                    }

                    $createdCustomers++;
                }

                $receivable = Receivable::create([
                    'company_id' => $user->company_id,
                    'customer_id' => $customer->id,
                    'upload_batch_id' => $batch->id,
                    'receivable_number' => $stagingRec->receivable_number,
                    'nosso_numero' => $stagingRec->nosso_numero,
                    'charge_type' => $stagingRec->charge_type,
                    'issue_date' => $stagingRec->issue_date,
                    'due_date' => $stagingRec->due_date,
                    'amount_total' => $stagingRec->amount_total,
                    'balance_amount' => $stagingRec->balance_amount ?: $stagingRec->amount_total,
                    'balance_without_interest' => $stagingRec->balance_without_interest ?: $stagingRec->amount_total,
                    'status' => $this->normalizeStatus($stagingRec->status_raw),
                    'snapshot_customer_name' => $customer->full_name,
                    'snapshot_customer_document' => $customer->document_number,
                    'snapshot_email_billing' => $stagingRec->email_billing ?: $customer->email_billing,
                    'is_active' => true,
                ]);

                $createdReceivables++;

                if ($receivable->snapshot_email_billing) {
                    try {
                        NotifierService::queueStandardMessage($user->company_id, $receivable, $user->id);
                        $queuedCount++;
                    } catch (\Exception $e) {
                    }
                }
            }

            $dispatchResult = NotifierService::dispatchPendingOutbox($user->company_id, 500);

            $batch->status = 'completed';
            $batch->preview_customers_total = $customerStats['total'];
            $batch->preview_receivables_total = $receivableStats['total'];
            $batch->preview_invalid_customers = $customerStats['invalid'];
            $batch->preview_invalid_receivables = $receivableStats['invalid'];
            $batch->preview_pending_links = 0;
            $batch->merged_customers_count = $createdCustomers;
            $batch->merged_receivables_count = $createdReceivables;
            $batch->error_message = null;
            $batch->save();

            StagingCustomer::where('upload_batch_id', $batch->id)->delete();
            StagingReceivable::where('upload_batch_id', $batch->id)->delete();

            echo json_encode([
                'status' => 'success',
                'message' => 'Lote processado com sucesso.',
                'batch_id' => (int) $batch->id,
                'customers_parsed' => $customerStats['accepted'],
                'receivables_parsed' => $receivableStats['accepted'],
                'customers_invalid' => $customerStats['invalid'],
                'receivables_invalid' => $receivableStats['invalid'],
                'emails_queued' => $queuedCount,
                'emails_sent_now' => $dispatchResult['sent'],
                'emails_failed' => $dispatchResult['errors'],
                'total_customers' => Customer::where('company_id', $user->company_id)->count(),
            ]);
        } catch (\Exception $e) {
            $batch->status = 'error';
            $batch->error_message = substr($e->getMessage(), 0, 2000);
            $batch->save();

            http_response_code(500);
            echo json_encode(['error' => 'Erro fatal no processamento: ' . $e->getMessage()]);
        }
    }
}
