<?php
namespace App\Controllers;

use App\Models\CustomerLinkPending;
use App\Models\StagingReceivable;
use App\Models\UploadBatch;
use App\Support\Auth;

class UploadBatchesController {
    private function getAuthedUser() {
        return Auth::userFromRequest();
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
}
