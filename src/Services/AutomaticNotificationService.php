<?php
namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\NotificationTemplate;
use App\Models\OutboxMessage;
use App\Models\Receivable;
use App\Models\UploadBatch;
use DateTimeImmutable;

class AutomaticNotificationService
{
    private const CLOSED_STATUSES = ['PAGO', 'CANCELADO', 'BAIXADO'];

    private const DEFAULT_TEMPLATES = [
        NotifierService::EVENT_REMINDER_7_DAYS => [
            'subject' => 'Faltam 7 dias para o vencimento do titulo {{receivable_number}}',
            'body' => "Ola, {{customer_name}}.\n\nEstamos passando para lembrar que o titulo {{receivable_number}} ({{charge_title}}) vence em {{due_date}}.\nValor: R$ {{amount_total}}\n\nSe o pagamento ja foi programado, desconsidere esta mensagem.",
        ],
        NotifierService::EVENT_DUE_TODAY => [
            'subject' => 'O titulo {{receivable_number}} vence hoje',
            'body' => "Ola, {{customer_name}}.\n\nEste e um lembrete de que o titulo {{receivable_number}} ({{charge_title}}) vence hoje, {{due_date}}.\nValor: R$ {{amount_total}}\nSaldo atual: R$ {{balance_amount}}\n\nSe o pagamento ja foi realizado, desconsidere esta mensagem.",
        ],
        NotifierService::EVENT_OVERDUE => [
            'subject' => 'Titulo vencido: {{receivable_number}}',
            'body' => "Ola, {{customer_name}}.\n\nIdentificamos que o titulo {{receivable_number}} ({{charge_title}}) esta vencido desde {{due_date}}.\nValor: R$ {{amount_total}}\nSaldo atual: R$ {{balance_amount}}\nDias em atraso: {{days_overdue}}\n\nPedimos, por gentileza, que verifique a regularizacao. Se o pagamento ja foi feito, desconsidere esta mensagem.",
        ],
    ];

    public static function determineEventCode(Receivable $receivable, ?DateTimeImmutable $referenceDate = null): ?string
    {
        if ((int) ($receivable->is_active ?? 1) !== 1) {
            return null;
        }

        $status = strtoupper(trim((string) ($receivable->status ?? '')));
        if ($status !== '' && in_array($status, self::CLOSED_STATUSES, true)) {
            return null;
        }

        $dueDate = NotifierService::parseCalendarDate($receivable->due_date);
        if (!$dueDate) {
            return null;
        }

        $today = $referenceDate ?: NotifierService::today();
        $todayLabel = $today->format('Y-m-d');

        if ($todayLabel === $dueDate->modify('-7 days')->format('Y-m-d')) {
            return NotifierService::EVENT_REMINDER_7_DAYS;
        }

        if ($todayLabel === $dueDate->format('Y-m-d')) {
            return NotifierService::EVENT_DUE_TODAY;
        }

        if ($today >= $dueDate->modify('+1 day')) {
            return NotifierService::EVENT_OVERDUE;
        }

        return null;
    }

    public static function eventDate(Receivable $receivable, string $eventCode): ?DateTimeImmutable
    {
        $dueDate = NotifierService::parseCalendarDate($receivable->due_date);
        if (!$dueDate) {
            return null;
        }

        return match ($eventCode) {
            NotifierService::EVENT_REMINDER_7_DAYS => $dueDate->modify('-7 days'),
            NotifierService::EVENT_DUE_TODAY => $dueDate,
            NotifierService::EVENT_OVERDUE => $dueDate->modify('+1 day'),
            default => null,
        };
    }

    public static function ensureTemplates(int $companyId): void
    {
        foreach (self::DEFAULT_TEMPLATES as $eventCode => $definition) {
            NotificationTemplate::firstOrCreate(
                [
                    'company_id' => $companyId,
                    'event_code' => $eventCode,
                ],
                [
                    'subject' => $definition['subject'],
                    'body' => $definition['body'],
                    'is_active' => true,
                ]
            );
        }
    }

    private static function eventLabel(string $eventCode): string
    {
        return match ($eventCode) {
            NotifierService::EVENT_REMINDER_7_DAYS => 'Lembrete de 7 dias',
            NotifierService::EVENT_DUE_TODAY => 'Vencimento hoje',
            NotifierService::EVENT_OVERDUE => 'Titulo vencido',
            default => 'Notificacao automatica',
        };
    }

    private static function resolveCustomer(int $companyId, Receivable $receivable): Customer
    {
        if ($receivable->relationLoaded('customer') && $receivable->customer) {
            return $receivable->customer;
        }

        $customer = Customer::where('company_id', $companyId)
            ->where('id', $receivable->customer_id)
            ->first();

        if (!$customer) {
            throw new \RuntimeException('Cliente da cobranca nao encontrado.');
        }

        return $customer;
    }

    private static function resolveUploadBatch(Receivable $receivable): ?UploadBatch
    {
        if ($receivable->relationLoaded('uploadBatch')) {
            return $receivable->uploadBatch;
        }

        if (!$receivable->upload_batch_id) {
            return null;
        }

        return UploadBatch::find($receivable->upload_batch_id);
    }

    private static function resolveRecipient(Customer $customer, Receivable $receivable): string
    {
        $recipient = trim((string) ($receivable->snapshot_email_billing ?: $customer->email_billing));

        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Nao existe um e-mail valido para essa cobranca.');
        }

        return $recipient;
    }

    private static function daysOverdue(Receivable $receivable, DateTimeImmutable $referenceDate): int
    {
        $dueDate = NotifierService::parseCalendarDate($receivable->due_date);
        if (!$dueDate || $referenceDate <= $dueDate) {
            return 0;
        }

        return (int) $dueDate->diff($referenceDate)->format('%a');
    }

    private static function buildContext(
        Customer $customer,
        Receivable $receivable,
        string $eventCode,
        DateTimeImmutable $referenceDate
    ): array {
        $uploadBatch = self::resolveUploadBatch($receivable);

        $amountTotal = $receivable->amount_total;
        $balanceAmount = $receivable->balance_amount;
        $balanceWithoutInterest = $receivable->balance_without_interest;

        if ($balanceAmount === null || $balanceAmount === '') {
            $balanceAmount = $amountTotal;
        }

        if ($balanceWithoutInterest === null || $balanceWithoutInterest === '') {
            $balanceWithoutInterest = $balanceAmount;
        }

        $chargeTitle = trim((string) ($receivable->charge_type ?: ''));
        if ($chargeTitle === '') {
            $chargeTitle = (string) ($receivable->receivable_number ?: $receivable->nosso_numero ?: 'Titulo em aberto');
        }

        return [
            'customer_name' => (string) ($receivable->snapshot_customer_name ?: $customer->full_name),
            'customer_document' => (string) ($receivable->snapshot_customer_document ?: $customer->document_number ?: ''),
            'customer_email' => (string) ($receivable->snapshot_email_billing ?: $customer->email_billing ?: ''),
            'receivable_number' => (string) ($receivable->receivable_number ?: $receivable->nosso_numero ?: ('#' . $receivable->id)),
            'nosso_numero' => (string) ($receivable->nosso_numero ?: ''),
            'charge_title' => $chargeTitle,
            'due_date' => NotifierService::formatDate($receivable->due_date),
            'amount_total' => NotifierService::formatMoney($amountTotal),
            'balance_amount' => NotifierService::formatMoney($balanceAmount),
            'balance_without_interest' => NotifierService::formatMoney($balanceWithoutInterest),
            'status' => (string) ($receivable->status ?? ''),
            'days_overdue' => (string) self::daysOverdue($receivable, $referenceDate),
            'imported_at' => NotifierService::formatDateTime($uploadBatch?->created_at ?: $receivable->created_at),
            'event_label' => self::eventLabel($eventCode),
        ];
    }

    private static function templateForCompany(int $companyId, string $eventCode): NotificationTemplate
    {
        self::ensureTemplates($companyId);

        $template = NotificationTemplate::where('company_id', $companyId)
            ->where('event_code', $eventCode)
            ->first();

        if (!$template) {
            throw new \RuntimeException('Template automatico nao encontrado para o evento ' . $eventCode . '.');
        }

        return $template;
    }

    private static function dedupeKey(int $companyId, Receivable $receivable, string $eventCode, DateTimeImmutable $eventDate): string
    {
        return hash('sha256', implode('|', [
            'automatic',
            $companyId,
            $receivable->id,
            $eventCode,
            $eventDate->format('Y-m-d'),
        ]));
    }

    public static function scheduleEvent(
        int $companyId,
        Receivable $receivable,
        string $eventCode,
        ?DateTimeImmutable $referenceDate = null,
        bool $dryRun = false
    ): array {
        $eventDate = self::eventDate($receivable, $eventCode);
        if (!$eventDate) {
            throw new \RuntimeException('A cobranca precisa ter uma data de vencimento valida para o envio automatico.');
        }

        $customer = self::resolveCustomer($companyId, $receivable);
        $recipient = self::resolveRecipient($customer, $receivable);
        $template = self::templateForCompany($companyId, $eventCode);
        $referenceDate = $referenceDate ?: NotifierService::today();
        $context = self::buildContext($customer, $receivable, $eventCode, $referenceDate);
        [$subject, $body] = NotifierService::renderTemplate($template->subject, $template->body, $context);

        $dedupeKey = self::dedupeKey($companyId, $receivable, $eventCode, $eventDate);
        $existing = OutboxMessage::where('company_id', $companyId)
            ->where('dedupe_key', $dedupeKey)
            ->first();

        if ($existing) {
            return [
                'created' => false,
                'duplicate' => true,
                'would_create' => false,
                'outbox' => $existing,
                'event_code' => $eventCode,
                'scheduled_for_date' => $eventDate->format('Y-m-d'),
            ];
        }

        if ($dryRun) {
            return [
                'created' => false,
                'duplicate' => false,
                'would_create' => true,
                'outbox' => null,
                'event_code' => $eventCode,
                'scheduled_for_date' => $eventDate->format('Y-m-d'),
                'recipient_email' => $recipient,
                'subject' => $subject,
            ];
        }

        $outbox = OutboxMessage::create([
            'company_id' => $companyId,
            'receivable_id' => $receivable->id,
            'customer_id' => $customer->id,
            'created_by_user_id' => null,
            'message_kind' => 'automatic',
            'notification_event' => $eventCode,
            'scheduled_for_date' => $eventDate->format('Y-m-d'),
            'recipient_email' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'dedupe_key' => $dedupeKey,
            'status' => 'pending',
        ]);

        return [
            'created' => true,
            'duplicate' => false,
            'would_create' => false,
            'outbox' => $outbox,
            'event_code' => $eventCode,
            'scheduled_for_date' => $eventDate->format('Y-m-d'),
        ];
    }

    public static function runForCompany(
        int $companyId,
        ?string $referenceDate = null,
        bool $dryRun = false,
        int $dispatchLimit = 200
    ): array {
        $today = NotifierService::today($referenceDate);
        $summary = [
            'company_id' => $companyId,
            'reference_date' => $today->format('Y-m-d'),
            'dry_run' => $dryRun,
            'receivables_checked' => 0,
            'scheduled' => 0,
            'skipped_no_event' => 0,
            'skipped_duplicate' => 0,
            'skipped_missing_email' => 0,
            'errors' => 0,
            'scheduled_by_event' => [
                NotifierService::EVENT_REMINDER_7_DAYS => 0,
                NotifierService::EVENT_DUE_TODAY => 0,
                NotifierService::EVENT_OVERDUE => 0,
            ],
            'error_items' => [],
            'dispatch' => [
                'sent' => 0,
                'errors' => 0,
                'processed_ids' => [],
            ],
        ];

        $receivables = Receivable::where('company_id', $companyId)
            ->where('is_active', 1)
            ->whereNotNull('due_date')
            ->whereNotIn('status', self::CLOSED_STATUSES)
            ->with(['customer', 'uploadBatch'])
            ->orderBy('due_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($receivables as $receivable) {
            $summary['receivables_checked']++;

            $eventCode = self::determineEventCode($receivable, $today);
            if ($eventCode === null) {
                $summary['skipped_no_event']++;
                continue;
            }

            try {
                $result = self::scheduleEvent($companyId, $receivable, $eventCode, $today, $dryRun);

                if (!empty($result['duplicate'])) {
                    $summary['skipped_duplicate']++;
                    continue;
                }

                if (!empty($result['created']) || !empty($result['would_create'])) {
                    $summary['scheduled']++;
                    $summary['scheduled_by_event'][$eventCode]++;
                }
            } catch (\Throwable $e) {
                if (str_contains(strtolower($e->getMessage()), 'e-mail valido')) {
                    $summary['skipped_missing_email']++;
                    continue;
                }

                $summary['errors']++;
                $summary['error_items'][] = [
                    'receivable_id' => (int) $receivable->id,
                    'event_code' => $eventCode,
                    'message' => $e->getMessage(),
                ];
            }
        }

        if (!$dryRun && $dispatchLimit > 0) {
            $summary['dispatch'] = NotifierService::dispatchPendingOutbox($companyId, $dispatchLimit, ['automatic']);
        }

        return $summary;
    }

    public static function runForAllCompanies(
        ?string $referenceDate = null,
        bool $dryRun = false,
        int $dispatchLimit = 200
    ): array {
        $companies = Company::where('is_active', 1)
            ->orderBy('id', 'asc')
            ->get();

        $results = [];
        foreach ($companies as $company) {
            $results[] = self::runForCompany((int) $company->id, $referenceDate, $dryRun, $dispatchLimit);
        }

        return [
            'reference_date' => NotifierService::today($referenceDate)->format('Y-m-d'),
            'dry_run' => $dryRun,
            'companies_processed' => count($results),
            'results' => $results,
        ];
    }
}
