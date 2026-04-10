<?php
namespace App\Controllers;

use App\Models\UploadBatch;
use App\Services\ImportBatchService;
use App\Services\ImportStorageService;
use App\Support\Auth;

class ImportsApiController {
    private function getAuthedUser() {
        return Auth::userFromRequest();
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
        $customersFilename = $_FILES['customers_upload']['name'] ?? 'clientes.xlsx';
        $receivablesFilename = $_FILES['receivables_upload']['name'] ?? 'cobrancas.xlsx';
        $customersHash = hash_file('sha256', $customersFile);
        $receivablesHash = hash_file('sha256', $receivablesFile);

        $existingBatch = UploadBatch::where('company_id', $user->company_id)
            ->where('customers_hash', $customersHash)
            ->where('receivables_hash', $receivablesHash)
            ->whereIn('status', ['processing', 'completed'])
            ->orderBy('id', 'desc')
            ->first();

        if ($existingBatch) {
            http_response_code(409);
            echo json_encode([
                'error' => 'Esse mesmo lote de planilhas ja foi importado anteriormente.',
                'existing_batch_id' => (int) $existingBatch->id,
                'existing_batch_status' => $existingBatch->status,
                'existing_batch_created_at' => $existingBatch->created_at,
            ]);
            return;
        }

        try {
            $storedCustomersFile = ImportStorageService::persistUploadedFile(
                (int) $user->company_id,
                $customersFile,
                $customersHash,
                $customersFilename,
                'customers'
            );
            $storedReceivablesFile = ImportStorageService::persistUploadedFile(
                (int) $user->company_id,
                $receivablesFile,
                $receivablesHash,
                $receivablesFilename,
                'receivables'
            );
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Nao foi possivel salvar as planilhas para reprocessamento: ' . $e->getMessage()]);
            return;
        }

        $batch = UploadBatch::create([
            'company_id' => $user->company_id,
            'uploaded_by_user_id' => $user->id,
            'customers_filename' => $customersFilename,
            'receivables_filename' => $receivablesFilename,
            'customers_hash' => $customersHash,
            'receivables_hash' => $receivablesHash,
            'status' => 'processing',
        ]);

        $result = ImportBatchService::process($user, $batch, $storedCustomersFile, $storedReceivablesFile);
        http_response_code($result['http_status']);
        echo json_encode($result['payload']);
    }
}
