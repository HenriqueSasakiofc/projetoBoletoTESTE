<?php
namespace App\Controllers;

use App\Models\Receivable;
use App\Services\NotifierService;
use App\Support\Auth;

class ReceivablesController {
    private function getAuthedUser() {
        return Auth::userFromRequest();
    }

    public function queueStandardMessage($id) {
        header('Content-Type: application/json');

        $user = $this->getAuthedUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $receivable = Receivable::where('company_id', $user->company_id)
            ->where('id', $id)
            ->with('customer')
            ->first();

        if (!$receivable) {
            http_response_code(404);
            echo json_encode(['error' => 'Cobranca nao encontrada.']);
            return;
        }

        $recipient = $receivable->snapshot_email_billing ?: ($receivable->customer->email_billing ?? null);
        if (!$recipient) {
            http_response_code(422);
            echo json_encode(['error' => 'Essa cobranca nao possui e-mail de destino para a mensagem padrao.']);
            return;
        }

        try {
            $message = NotifierService::queueStandardMessage($user->company_id, $receivable, $user->id);

            echo json_encode([
                'status' => 'queued',
                'message' => $message->wasRecentlyCreated
                    ? 'Mensagem padrao colocada na fila com sucesso.'
                    : 'Essa mensagem padrao ja estava na fila para essa cobranca.',
                'outbox_message_id' => (int) $message->id,
            ]);
        } catch (\Exception $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
