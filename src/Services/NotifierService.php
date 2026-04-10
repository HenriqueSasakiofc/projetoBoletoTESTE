<?php
namespace App\Services;

use App\Models\Customer;
use App\Models\MessageTemplate;
use App\Models\NotificationTemplate;
use App\Models\OutboxMessage;
use App\Models\Receivable;
use App\Models\UploadBatch;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PHPMailer\PHPMailer\PHPMailer;

class NotifierService {
    public const EVENT_REMINDER_7_DAYS = 'lembrete_7_dias';
    public const EVENT_DUE_TODAY = 'vencimento_hoje';
    public const EVENT_OVERDUE = 'vencido';

    protected const ALLOWED_PLACEHOLDERS = [
        'customer_name',
        'customer_document',
        'customer_email',
        'receivable_number',
        'nosso_numero',
        'charge_title',
        'due_date',
        'amount_total',
        'balance_amount',
        'balance_without_interest',
        'status',
        'days_overdue',
        'imported_at',
        'event_label',
    ];

    protected const AUTOMATIC_TEMPLATE_DEFINITIONS = [
        self::EVENT_REMINDER_7_DAYS => [
            'subject' => 'Faltam 7 dias para o vencimento do titulo {{receivable_number}}',
            'body' => "Ola, {{customer_name}}.\n\nEstamos passando para lembrar que o titulo {{receivable_number}} ({{charge_title}}) vence em {{due_date}}.\nValor: R$ {{amount_total}}\n\nSe o pagamento ja foi programado, desconsidere esta mensagem.",
        ],
        self::EVENT_DUE_TODAY => [
            'subject' => 'O titulo {{receivable_number}} vence hoje',
            'body' => "Ola, {{customer_name}}.\n\nEste e um lembrete de que o titulo {{receivable_number}} ({{charge_title}}) vence hoje, {{due_date}}.\nValor: R$ {{amount_total}}\nSaldo atual: R$ {{balance_amount}}\n\nSe o pagamento ja foi realizado, desconsidere esta mensagem.",
        ],
        self::EVENT_OVERDUE => [
            'subject' => 'Titulo vencido: {{receivable_number}}',
            'body' => "Ola, {{customer_name}}.\n\nIdentificamos que o titulo {{receivable_number}} ({{charge_title}}) esta vencido desde {{due_date}}.\nValor: R$ {{amount_total}}\nSaldo atual: R$ {{balance_amount}}\nDias em atraso: {{days_overdue}}\n\nPedimos, por gentileza, que verifique a regularizacao. Se o pagamento ja foi feito, desconsidere esta mensagem.",
        ],
    ];

    public static function defaultTimezone(): DateTimeZone {
        return new DateTimeZone($_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo');
    }

    public static function today(?string $referenceDate = null): DateTimeImmutable {
        $timezone = self::defaultTimezone();
        $value = $referenceDate ?: 'now';
        return (new DateTimeImmutable($value, $timezone))->setTime(0, 0, 0);
    }

    public static function parseCalendarDate($value): ?DateTimeImmutable {
        if ($value instanceof DateTimeInterface) {
            return (new DateTimeImmutable($value->format('Y-m-d H:i:s'), self::defaultTimezone()))->setTime(0, 0, 0);
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (new DateTimeImmutable((string) $value, self::defaultTimezone()))->setTime(0, 0, 0);
    }

    public static function parseDateTime($value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return new DateTimeImmutable($value->format('Y-m-d H:i:s'), self::defaultTimezone());
        }

        if ($value === null || $value === '') {
            return null;
        }

        return new DateTimeImmutable((string) $value, self::defaultTimezone());
    }

    public static function formatMoney($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float) $value, 2, ',', '.');
    }

    public static function formatDate($value): string
    {
        $date = self::parseCalendarDate($value);

        return $date ? $date->format('d/m/Y') : '';
    }

    public static function formatDateTime($value): string
    {
        $date = self::parseDateTime($value);

        return $date ? $date->format('d/m/Y H:i') : '';
    }

    public static function renderTemplate(string $subject, string $body, array $context): array {
        if (empty(trim($body))) {
            throw new \Exception("Corpo da mensagem é obrigatório.");
        }

        $pattern = '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/';
        
        $replacer = function($matches) use ($context) {
            $key = trim($matches[1]);
            if (!in_array($key, self::ALLOWED_PLACEHOLDERS)) {
                throw new \Exception("Placeholder inválido: " . $key);
            }
            return $context[$key] ?? "";
        };

        $rendered_subject = preg_replace_callback($pattern, $replacer, $subject);
        $rendered_body = preg_replace_callback($pattern, $replacer, $body);

        return [$rendered_subject, $rendered_body];
    }

    public static function buildDedupeKey(int $companyId, int $targetId, string $subject, string $body, string $type = 'standard'): string {
        $base = "{$type}|{$companyId}|{$targetId}|{$subject}|{$body}";
        return hash('sha256', $base);
    }

    public static function queueStandardMessage(int $companyId, Receivable $receivable, ?int $userId = null): OutboxMessage {
        $customer = Customer::where('id', $receivable->customer_id)
            ->where('company_id', $companyId)->first();

        if (!$customer) {
            throw new \Exception("Cliente da cobrança não encontrado.");
        }

        $recipient = $receivable->snapshot_email_billing ?: $customer->email_billing;

        if (!$recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new \Exception("Nao existe um e-mail valido para essa cobranca.");
        }

        // VALIDAÇÃO CUSTÓMIZADA: Não enviar email para a mesma pessoa mais de uma vez num período curto (ex: 24 horas)
        // Dessa forma, se você subir a planilha semana que vem cobrando a mesma conta não paga, ele permitirá enviar!
        $yesterday = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $alreadySentToPerson = OutboxMessage::where('company_id', $companyId)
            ->where('recipient_email', $recipient)
            ->where('receivable_id', $receivable->id)
            ->where('created_at', '>=', $yesterday)
            ->exists();

        if ($alreadySentToPerson) {
            throw new \Exception("Validação bloqueada: Um e-mail alertando o título {$receivable->receivable_number} já foi enviado para {$recipient} nas últimas 24 horas. Para evitar Spam, aguarde.");
        }

        $template = MessageTemplate::where('company_id', $companyId)->first();
        if (!$template) {
            // Create default template as fallback
            $template = MessageTemplate::create([
                'company_id' => $companyId,
                'subject' => "Lembrete de vencimento - {{receivable_number}}",
                'body' => "Olá, {{customer_name}}.\n\nEste é um lembrete sobre o título {{receivable_number}}.\nVencimento: {{due_date}}\nValor: R$ {{amount_total}}\nSaldo: R$ {{balance_amount}}\n\nSe o pagamento já foi realizado, desconsidere.",
                'is_active' => true
            ]);
        }

        $context = [
            "customer_name" => $customer->full_name,
            "customer_document" => $customer->document_number,
            "customer_email" => $customer->email_billing,
            "receivable_number" => $receivable->receivable_number,
            "nosso_numero" => $receivable->nosso_numero,
            "due_date" => self::formatDate($receivable->due_date),
            "amount_total" => self::formatMoney($receivable->amount_total),
            "balance_amount" => self::formatMoney($receivable->balance_amount),
            "status" => $receivable->status,
        ];

        list($subject, $body) = self::renderTemplate($template->subject, $template->body, $context);
        
        $dedupeKey = self::buildDedupeKey($companyId, $receivable->id, $subject, $body, 'standard');

        $existing = OutboxMessage::where('company_id', $companyId)
            ->where('dedupe_key', $dedupeKey)->first();

        if ($existing) {
            return $existing;
        }

        return OutboxMessage::create([
            'company_id' => $companyId,
            'receivable_id' => $receivable->id,
            'customer_id' => $customer->id,
            'created_by_user_id' => $userId,
            'message_kind' => 'standard',
            'recipient_email' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'dedupe_key' => $dedupeKey,
            'status' => 'pending'
        ]);
    }

    public static function dispatchPendingOutbox(int $companyId, int $limit = 20, ?array $messageKinds = null): array {
        $limit = max(1, min(200, $limit));

        $query = OutboxMessage::where('company_id', $companyId)
            ->whereIn('status', ['pending', 'error'])
            ->orderBy('created_at', 'asc');

        if (is_array($messageKinds) && !empty($messageKinds)) {
            $query->whereIn('message_kind', $messageKinds);
        }

        $messages = $query
            ->limit($limit)
            ->get();

        $sent = 0;
        $errors = 0;
        $processedIds = [];

        foreach ($messages as $msg) {
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = $_ENV['SMTP_HOST'];
                $mail->SMTPAuth   = !empty($_ENV['SMTP_USERNAME']);
                $mail->Username   = $_ENV['SMTP_USERNAME'];
                $mail->Password   = $_ENV['SMTP_PASSWORD'];
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = $_ENV['SMTP_PORT'] ?? 587;
                $mail->CharSet    = 'UTF-8';

                $mail->setFrom($_ENV['SMTP_FROM_EMAIL'], $_ENV['SMTP_FROM_NAME']);
                
                // Safe mode override for testing
                if (($_ENV['SAFE_MODE'] ?? 'false') === 'true') {
                    $recipient = $_ENV['TEST_EMAIL'];
                    $msgBody = "[SAFE_MODE ATIVO]\nDestinatário original: {$msg->recipient_email}\n\n{$msg->body}";
                } else {
                    $recipient = $msg->recipient_email;
                    $msgBody = $msg->body;
                }

                $mail->addAddress($recipient);
                $mail->Subject = $msg->subject;
                $mail->Body    = $msgBody;

                $mail->send();

                $msg->status = 'sent';
                $msg->sent_at = date('Y-m-d H:i:s');
                $msg->error_message = null;
                $msg->save();

                if ($msg->message_kind === 'standard' && $msg->receivable_id) {
                    Receivable::where('id', $msg->receivable_id)->update([
                        'last_standard_message_at' => date('Y-m-d H:i:s')
                    ]);
                }

                $sent++;
            } catch (\Exception $e) {
                $msg->status = 'error';
                $msg->error_message = substr($mail->ErrorInfo ?: $e->getMessage(), 0, 1000);
                $msg->save();
                $errors++;
            }
            $processedIds[] = $msg->id;
        }

        return [
            'sent' => $sent,
            'errors' => $errors,
            'processed_ids' => $processedIds
        ];
    }
}
