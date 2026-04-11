<?php
namespace App\Controllers;

use App\Models\Customer;
use App\Models\ManualMessage;
use App\Models\MessageTemplate;
use App\Models\NotificationTemplate;
use App\Models\OutboxMessage;
use App\Services\AutomaticNotificationService;
use App\Services\NotifierService;
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

    private function notificationEventLabels(): array {
        return [
            NotifierService::EVENT_REMINDER_7_DAYS => '1 semana antes do vencimento',
            NotifierService::EVENT_DUE_TODAY => 'No dia do vencimento',
            NotifierService::EVENT_OVERDUE => 'Depois que venceu',
        ];
    }

    public function getNotificationTemplates() {
        header('Content-Type: application/json');

        $user = $this->getAuthedUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorized']);
            return;
        }

        AutomaticNotificationService::ensureTemplates((int) $user->company_id);
        $labels = $this->notificationEventLabels();

        $templates = NotificationTemplate::where('company_id', $user->company_id)
            ->whereIn('event_code', array_keys($labels))
            ->get()
            ->keyBy('event_code');

        $items = [];
        foreach ($labels as $eventCode => $label) {
            $template = $templates->get($eventCode);
            $items[] = [
                'event_code' => $eventCode,
                'event_label' => $label,
                'subject' => $template->subject ?? '',
                'body' => $template->body ?? '',
                'is_active' => (bool) ($template->is_active ?? true),
            ];
        }

        echo json_encode([
            'templates' => $items,
            'placeholders' => [
                ['key' => 'customer_name', 'label' => 'Nome do cliente'],
                ['key' => 'customer_document', 'label' => 'Documento do cliente'],
                ['key' => 'customer_email', 'label' => 'E-mail do cliente'],
                ['key' => 'invoice_id', 'label' => 'ID da fatura'],
                ['key' => 'receivable_number', 'label' => 'Numero do titulo'],
                ['key' => 'nosso_numero', 'label' => 'Nosso numero'],
                ['key' => 'charge_title', 'label' => 'Tipo da cobranca'],
                ['key' => 'issue_date', 'label' => 'Data de lancamento'],
                ['key' => 'due_date', 'label' => 'Data de vencimento'],
                ['key' => 'amount_total', 'label' => 'Valor original'],
                ['key' => 'balance_amount', 'label' => 'Saldo atual'],
                ['key' => 'days_overdue', 'label' => 'Dias em atraso'],
                ['key' => 'imported_at', 'label' => 'Data de importacao'],
                ['key' => 'event_label', 'label' => 'Tipo de aviso'],
            ],
        ]);
    }

    public function updateNotificationTemplates() {
        header('Content-Type: application/json');

        $user = $this->getAuthedUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorized']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $templates = $input['templates'] ?? [];
        $labels = $this->notificationEventLabels();

        if (!is_array($templates)) {
            http_response_code(422);
            echo json_encode(['message' => 'Formato invalido para os templates.']);
            return;
        }

        foreach ($templates as $templateInput) {
            $eventCode = (string) ($templateInput['event_code'] ?? '');
            $subject = trim((string) ($templateInput['subject'] ?? ''));
            $body = trim((string) ($templateInput['body'] ?? ''));
            $isActive = array_key_exists('is_active', $templateInput) ? (bool) $templateInput['is_active'] : true;

            if (!array_key_exists($eventCode, $labels)) {
                http_response_code(422);
                echo json_encode(['message' => 'Evento de template invalido.']);
                return;
            }

            if ($subject === '' || $body === '') {
                http_response_code(422);
                echo json_encode(['message' => 'Assunto e corpo sao obrigatorios em todos os templates.']);
                return;
            }

            try {
                NotifierService::renderTemplate($subject, $body, array_fill_keys([
                    'customer_name',
                    'customer_document',
                    'customer_email',
                    'receivable_number',
                    'invoice_id',
                    'nosso_numero',
                    'charge_title',
                    'issue_date',
                    'invoice_issue_date',
                    'due_date',
                    'invoice_due_date',
                    'amount_total',
                    'balance_amount',
                    'balance_without_interest',
                    'status',
                    'days_overdue',
                    'imported_at',
                    'event_label',
                ], 'exemplo'));
            } catch (\Throwable $e) {
                http_response_code(422);
                echo json_encode(['message' => $e->getMessage()]);
                return;
            }

            NotificationTemplate::updateOrCreate(
                [
                    'company_id' => $user->company_id,
                    'event_code' => $eventCode,
                ],
                [
                    'subject' => $subject,
                    'body' => $body,
                    'is_active' => $isActive,
                ]
            );
        }

        echo json_encode([
            'status' => 'saved',
            'message' => 'Templates automaticos salvos com sucesso.',
        ]);
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
