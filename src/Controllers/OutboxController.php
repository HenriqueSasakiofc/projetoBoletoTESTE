<?php
namespace App\Controllers;

use App\Models\OutboxMessage;
use App\Services\AutomaticNotificationService;
use App\Services\NotifierService;
use App\Support\Auth;

class OutboxController
{
    private function getAuthedUser()
    {
        return Auth::userFromRequest();
    }

    private function formatDateTime($value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return (new \DateTime((string) $value))->format('d/m/Y H:i');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            'pending' => 'Pendente',
            'sent' => 'Enviado',
            'error' => 'Erro',
            default => ucfirst((string) $status),
        };
    }

    private function kindLabel(?string $kind): string
    {
        return match ($kind) {
            'automatic' => 'Automatico',
            'manual' => 'Manual',
            'standard' => 'Padrao',
            default => ucfirst((string) $kind),
        };
    }

    private function eventLabel(?string $event): string
    {
        return match ($event) {
            NotifierService::EVENT_REMINDER_7_DAYS => '7 dias antes',
            NotifierService::EVENT_DUE_TODAY => 'Vence hoje',
            NotifierService::EVENT_OVERDUE => 'Vencido',
            null, '' => '-',
            default => (string) $event,
        };
    }

    private function serializeMessage(OutboxMessage $message): array
    {
        $receivable = $message->receivable;
        $customer = $message->customer;

        return [
            'id' => (int) $message->id,
            'message_kind' => (string) $message->message_kind,
            'message_kind_label' => $this->kindLabel($message->message_kind),
            'notification_event' => $message->notification_event,
            'notification_event_label' => $this->eventLabel($message->notification_event),
            'scheduled_for_date' => $message->scheduled_for_date,
            'recipient_email' => (string) $message->recipient_email,
            'subject' => (string) $message->subject,
            'body_preview' => substr(trim((string) $message->body), 0, 180),
            'status' => (string) $message->status,
            'status_label' => $this->statusLabel($message->status),
            'error_message' => $message->error_message,
            'sent_at' => $message->sent_at,
            'sent_at_formatted' => $this->formatDateTime($message->sent_at),
            'created_at' => $message->created_at,
            'created_at_formatted' => $this->formatDateTime($message->created_at),
            'updated_at' => $message->updated_at,
            'updated_at_formatted' => $this->formatDateTime($message->updated_at),
            'customer_id' => $message->customer_id ? (int) $message->customer_id : null,
            'customer_name' => $customer->full_name ?? null,
            'receivable_id' => $message->receivable_id ? (int) $message->receivable_id : null,
            'receivable_number' => $receivable->receivable_number ?? $receivable->nosso_numero ?? null,
            'can_dispatch' => in_array($message->status, ['pending', 'error'], true),
        ];
    }

    private function countByStatus(int $companyId, string $status): int
    {
        return OutboxMessage::where('company_id', $companyId)
            ->where('status', $status)
            ->count();
    }

    public function index()
    {
        header('Content-Type: application/json');

        $user = $this->getAuthedUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $status = $_GET['status'] ?? 'all';
        $kind = $_GET['kind'] ?? 'all';
        $limit = max(1, min(300, (int) ($_GET['limit'] ?? 100)));

        $query = OutboxMessage::where('company_id', $user->company_id)
            ->with(['customer', 'receivable']);

        if (in_array($status, ['pending', 'sent', 'error'], true)) {
            $query->where('status', $status);
        }

        if (in_array($kind, ['automatic', 'manual', 'standard'], true)) {
            $query->where('message_kind', $kind);
        }

        $items = $query
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn ($message) => $this->serializeMessage($message))
            ->values();

        echo json_encode([
            'items' => $items,
            'summary' => [
                'total' => OutboxMessage::where('company_id', $user->company_id)->count(),
                'pending' => $this->countByStatus((int) $user->company_id, 'pending'),
                'sent' => $this->countByStatus((int) $user->company_id, 'sent'),
                'error' => $this->countByStatus((int) $user->company_id, 'error'),
            ],
        ]);
    }

    public function dispatch()
    {
        header('Content-Type: application/json');

        $user = $this->getAuthedUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $limit = max(1, min(200, (int) ($input['limit'] ?? 50)));
        $kind = $input['kind'] ?? 'all';
        $messageKinds = in_array($kind, ['automatic', 'manual', 'standard'], true) ? [$kind] : null;

        $result = NotifierService::dispatchPendingOutbox((int) $user->company_id, $limit, $messageKinds);

        echo json_encode([
            'status' => 'processed',
            'message' => 'Processamento da outbox concluido.',
            'sent' => $result['sent'],
            'errors' => $result['errors'],
            'processed_ids' => $result['processed_ids'],
        ]);
    }

    public function scheduleAutomatic()
    {
        header('Content-Type: application/json');

        $user = $this->getAuthedUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $referenceDate = isset($input['date']) && $input['date'] !== '' ? (string) $input['date'] : null;

        $result = AutomaticNotificationService::runForCompany(
            (int) $user->company_id,
            $referenceDate,
            false,
            0
        );

        echo json_encode([
            'status' => 'scheduled',
            'message' => 'Cobrancas automaticas avaliadas e colocadas na outbox quando aplicavel.',
            'summary' => $result,
        ]);
    }
}
