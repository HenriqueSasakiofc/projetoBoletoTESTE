<?php
namespace App\Services;

use App\Models\Customer;
use App\Models\Receivable;
use App\Models\StagingCustomer;
use App\Models\StagingReceivable;
use App\Models\UploadBatch;

class ImportBatchService
{
    private static function normalizeStatus(?string $rawStatus): string
    {
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

    public static function process(object $user, UploadBatch $batch, string $customersFile, string $receivablesFile): array
    {
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

                return [
                    'http_status' => 422,
                    'payload' => [
                        'error' => 'Nenhuma linha valida foi encontrada em uma ou ambas as planilhas. Verifique o cabecalho e os nomes das colunas.',
                        'batch_id' => (int) $batch->id,
                        'customers_total_rows' => $customerStats['total'],
                        'customers_valid_rows' => $customerStats['accepted'],
                        'customers_invalid_rows' => $customerStats['invalid'],
                        'receivables_total_rows' => $receivableStats['total'],
                        'receivables_valid_rows' => $receivableStats['accepted'],
                        'receivables_invalid_rows' => $receivableStats['invalid'],
                    ],
                ];
            }

            $createdCustomers = 0;
            $createdReceivables = 0;

            foreach ($stagedReceivables as $stagingRec) {
                $normName = $stagingRec->normalized_customer_name;
                $stagingCust = StagingCustomer::where('upload_batch_id', $batch->id)
                    ->where('normalized_name', $normName)
                    ->first();
                $stagingCustomerPayload = $stagingCust && $stagingCust->raw_payload
                    ? (json_decode($stagingCust->raw_payload, true) ?: [])
                    : [];

                $customer = Customer::where('company_id', $user->company_id)
                    ->where('normalized_name', $normName)
                    ->first();

                if (!$customer) {
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
                            'is_active' => ImporterService::inferCustomerIsActiveFromRow($stagingCustomerPayload),
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
                } elseif ($stagingCust) {
                    $customer->external_code = $stagingCust->external_code ?: $customer->external_code;
                    $customer->full_name = $stagingCust->full_name ?: $customer->full_name;
                    $customer->normalized_name = $stagingCust->normalized_name ?: $customer->normalized_name;
                    $customer->document_number = $stagingCust->document_number ?: $customer->document_number;
                    $customer->email_billing = $stagingCust->email_billing ?: $customer->email_billing;
                    $customer->email_financial = $stagingCust->email_financial ?: $customer->email_financial;
                    $customer->phone = $stagingCust->phone ?: $customer->phone;
                    $customer->other_contacts = $stagingCust->other_contacts ?: $customer->other_contacts;
                    $customer->is_active = ImporterService::inferCustomerIsActiveFromRow($stagingCustomerPayload);
                    $customer->save();
                }

                $receivableQuery = Receivable::where('company_id', $user->company_id)
                    ->where('customer_id', $customer->id);

                if ($stagingRec->receivable_number) {
                    $receivableQuery->where('receivable_number', $stagingRec->receivable_number);
                } elseif ($stagingRec->nosso_numero) {
                    $receivableQuery->where('nosso_numero', $stagingRec->nosso_numero);
                } else {
                    $receivableQuery->where('due_date', $stagingRec->due_date)
                        ->where('amount_total', $stagingRec->amount_total);
                }

                $receivable = $receivableQuery->first();
                $isNewReceivable = false;

                if (!$receivable) {
                    $receivable = new Receivable();
                    $receivable->company_id = $user->company_id;
                    $receivable->customer_id = $customer->id;
                    $isNewReceivable = true;
                }

                $receivable->upload_batch_id = $batch->id;
                $receivable->receivable_number = $stagingRec->receivable_number;
                $receivable->nosso_numero = $stagingRec->nosso_numero;
                $receivable->charge_type = $stagingRec->charge_type;
                $receivable->issue_date = $stagingRec->issue_date;
                $receivable->due_date = $stagingRec->due_date;
                $receivable->amount_total = $stagingRec->amount_total;
                $receivable->balance_amount = $stagingRec->balance_amount ?: $stagingRec->amount_total;
                $receivable->balance_without_interest = $stagingRec->balance_without_interest ?: $stagingRec->amount_total;
                $receivable->status = self::normalizeStatus($stagingRec->status_raw);
                $receivable->snapshot_customer_name = $customer->full_name;
                $receivable->snapshot_customer_document = $customer->document_number;
                $receivable->snapshot_email_billing = $stagingRec->email_billing ?: $customer->email_billing;
                $receivable->is_active = true;
                $receivable->save();

                if ($isNewReceivable) {
                    $createdReceivables++;
                }

            }

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

            return [
                'http_status' => 200,
                'payload' => [
                    'status' => 'success',
                    'message' => 'Lote processado com sucesso.',
                    'batch_id' => (int) $batch->id,
                    'customers_parsed' => $customerStats['accepted'],
                    'receivables_parsed' => $receivableStats['accepted'],
                    'customers_invalid' => $customerStats['invalid'],
                    'receivables_invalid' => $receivableStats['invalid'],
                    'emails_queued' => 0,
                    'emails_sent_now' => 0,
                    'emails_failed' => 0,
                    'total_customers' => Customer::where('company_id', $user->company_id)->count(),
                ],
            ];
        } catch (\Exception $e) {
            $batch->status = 'error';
            $batch->error_message = substr($e->getMessage(), 0, 2000);
            $batch->save();

            return [
                'http_status' => 500,
                'payload' => [
                    'error' => 'Erro fatal no processamento: ' . $e->getMessage(),
                    'batch_id' => (int) $batch->id,
                ],
            ];
        }
    }
}
