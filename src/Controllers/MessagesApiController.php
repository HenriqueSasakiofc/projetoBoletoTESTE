<?php
namespace App\Controllers;

use App\Models\Customer;
use App\Models\ManualMessage;
use App\Models\MessageTemplate;
use App\Models\OutboxMessage;
use App\Support\Auth;

class MessagesApiController {
    private function getAuthedUser() {
        return Auth::userFromRequest();
    }

    public function getTemplate() {
        header('Content-Type: application/json');

        $user = $this->getAuthedUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorized']);
            return;
        }

        $template = MessageTemplate::where('company_id', $user->company_id)->first();
        if (!$template) {
            echo json_encode([
                'subject' => '',
                'body' => '',
                'is_active' => true
            ]);
            return;
        }

        echo json_encode($template);
    }

    public function updateTemplate() {
        header('Content-Type: application/json');

        $user = $this->getAuthedUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorized']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $subject = trim($input['subject'] ?? '');
        $body = trim($input['body'] ?? '');

        if ($subject === '' || $body === '') {
            http_response_code(422);
            echo json_encode(['message' => 'Assunto e corpo sao obrigatorios.']);
            return;
        }

        $template = MessageTemplate::updateOrCreate(
            ['company_id' => $user->company_id],
            ['subject' => $subject, 'body' => $body, 'is_active' => true]
        );

        echo json_encode($template);
    }

    public function sendManual($id) {
        header('Content-Type: application/json');

        $user = $this->getAuthedUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorized']);
            return;
        }

        $customer = Customer::where('company_id', $user->company_id)
            ->where('id', $id)
            ->first();

        if (!$customer) {
            http_response_code(404);
            echo json_encode(['message' => 'Cliente nao encontrado.']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $recipientEmail = trim(strtolower($input['recipient_email'] ?? ''));
        $subject = trim($input['subject'] ?? '');
        $body = trim($input['body'] ?? '');

        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['message' => 'Informe um e-mail de destino valido.']);
            return;
        }

        if ($subject === '' || $body === '') {
            http_response_code(422);
            echo json_encode(['message' => 'Assunto e mensagem sao obrigatorios.']);
            return;
        }

        $previewHash = hash('sha256', implode('|', [
            $user->company_id,
            $customer->id,
            $recipientEmail,
            $subject,
            $body
        ]));

        $manualMessage = ManualMessage::create([
            'company_id' => $user->company_id,
            'customer_id' => $customer->id,
            'created_by_user_id' => $user->id,
            'recipient_email' => $recipientEmail,
            'subject' => $subject,
            'body' => $body,
            'preview_hash' => $previewHash,
        ]);

        $outbox = OutboxMessage::create([
            'company_id' => $user->company_id,
            'customer_id' => $customer->id,
            'created_by_user_id' => $user->id,
            'message_kind' => 'manual',
            'recipient_email' => $recipientEmail,
            'subject' => $subject,
            'body' => $body,
            'dedupe_key' => hash('sha256', 'manual|' . $manualMessage->id . '|' . $previewHash),
            'status' => 'pending',
        ]);

        echo json_encode([
            'status' => 'queued',
            'message' => 'Mensagem manual colocada na fila com sucesso.',
            'manual_message_id' => (int) $manualMessage->id,
            'outbox_message_id' => (int) $outbox->id,
        ]);
    }
}
