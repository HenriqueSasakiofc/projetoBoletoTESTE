<?php
namespace App\Controllers;

use App\Models\CustomerLinkPending;
use App\Models\StagingReceivable;
use App\Models\UploadBatch;
use App\Services\ImportBatchService;
use App\Services\ImportStorageService;
use App\Services\UploadBatchMaintenanceService;
use App\Support\Auth;

class UploadBatchesController {
    private function getAuthedUser() {
        return Auth::userFromRequest();
    }

    private function formatDateTime($value): ?string {
        if (!$value) {
            return null;
        }

        try {
            return (new \DateTime((string) $value))->format('d/m/Y H:i');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function statusLabel(string $status): string {
        return match ($status) {
            'completed' => 'Concluido',
            'processing' => 'Processando',
            'error' => 'Com erro',
            'purged' => 'Purgado',
            default => ucfirst($status),
        };
    }

    private function serializeBatch(UploadBatch $batch): array {
        $storedFilesAvailable = ImportStorageService::hasStoredFiles($batch);

        return [
            'id' => (int) $batch->id,
            'status' => (string) $batch->status,
            'status_label' => $this->statusLabel((string) $batch->status),
            'customers_filename' => (string) $batch->customers_filename,
            'receivables_filename' => (string) $batch->receivables_filename,
            'preview_customers_total' => (int) $batch->preview_customers_total,
            'preview_receivables_total' => (int) $batch->preview_receivables_total,
            'preview_invalid_customers' => (int) $batch->preview_invalid_customers,
            'preview_invalid_receivables' => (int) $batch->preview_invalid_receivables,
            'merged_customers_count' => (int) $batch->merged_customers_count,
            'merged_receivables_count' => (int) $batch->merged_receivables_count,
            'error_message' => $batch->error_message,
            'created_at' => $batch->created_at,
            'created_at_formatted' => $this->formatDateTime($batch->created_at),
            'updated_at' => $batch->updated_at,
            'updated_at_formatted' => $this->formatDateTime($batch->updated_at),
            'stored_files_available' => $storedFilesAvailable,
            'can_reprocess' => $storedFilesAvailable && $batch->status !== 'processing',
            'reprocess_hint' => $storedFilesAvailable
                ? 'Esse lote pode ser reprocessado sem pedir um novo envio ao cliente.'
                : 'Esse lote nao tem as planilhas salvas no servidor. Reenvie uma vez para habilitar o reprocessamento automatico.',
        ];
    }

    public function latest() {
        header('Content-Type: application/json');

        $user = $this->getAuthedUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $batch = UploadBatch::where('company_id', $user->company_id)
            ->orderBy('id', 'desc')
            ->first();

        echo json_encode($batch ? $this->serializeBatch($batch) : null);
    }

    public function pendings($id) {
        header('Content-Type: application/json');

        $user = $this->getAuthedUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $batch = UploadBatch::where('company_id', $user->company_id)
            ->where('id', $id)
            ->first();

        if (!$batch) {
            http_response_code(404);
            echo json_encode(['error' => 'Lote nao encontrado.']);
            return;
        }

        $items = CustomerLinkPending::where('company_id', $user->company_id)
            ->where('upload_batch_id', $id)
            ->where('status', 'open')
            ->orderBy('id')
            ->get()
            ->map(function ($pending) {
                $stagingReceivable = StagingReceivable::find($pending->staging_receivable_id);

                return [
                    'id' => (int) $pending->id,
                    'customer_name' => $stagingReceivable->customer_name ?? null,
                    'customer_document_number' => $stagingReceivable->customer_document_number ?? null,
                    'receivable_number' => $stagingReceivable->receivable_number ?? null,
                    'amount_total' => $stagingReceivable->amount_total ?? null,
                    'due_date' => $stagingReceivable->due_date ?? null,
                    'status' => $pending->status,
                    'note' => $pending->note,
                ];
            })
            ->values();

        echo json_encode($items);
    }

    public function reprocess($id) {
        header('Content-Type: application/json');

        $user = $this->getAuthedUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $batch = UploadBatch::where('company_id', $user->company_id)
            ->where('id', $id)
            ->first();

        if (!$batch) {
            http_response_code(404);
            echo json_encode(['error' => 'Lote nao encontrado.']);
            return;
        }

        if ($batch->status === 'processing') {
            http_response_code(409);
            echo json_encode(['error' => 'Esse lote ainda esta em processamento. Aguarde a conclusao antes de tentar novamente.']);
            return;
        }

        $customersFile = ImportStorageService::resolveBatchFile($batch, 'customers');
        $receivablesFile = ImportStorageService::resolveBatchFile($batch, 'receivables');

        if (!$customersFile || !$receivablesFile) {
            http_response_code(422);
            echo json_encode([
                'error' => 'As planilhas originais desse lote nao estao mais salvas no servidor. Reenvie os arquivos uma vez para habilitar o reprocessamento automatico.',
                'batch' => $this->serializeBatch($batch),
            ]);
            return;
        }

        $purgeSummary = UploadBatchMaintenanceService::purgeMatchingBatches($batch);

        $newBatch = UploadBatch::create([
            'company_id' => $user->company_id,
            'uploaded_by_user_id' => $user->id,
            'customers_filename' => $batch->customers_filename,
            'receivables_filename' => $batch->receivables_filename,
            'customers_hash' => $batch->customers_hash,
            'receivables_hash' => $batch->receivables_hash,
            'status' => 'processing',
        ]);

        $result = ImportBatchService::process($user, $newBatch, $customersFile, $receivablesFile);
        $payload = $result['payload'];
        $payload['reprocessed_from_batch_id'] = (int) $batch->id;
        $payload['purged_batch_ids'] = $purgeSummary['matching_batch_ids'];

        http_response_code($result['http_status']);
        echo json_encode($payload);
    }
}
