<?php
namespace App\Services;

use App\Models\OutboxMessage;
use App\Models\Receivable;
use App\Models\MessageTemplate;
use App\Models\Customer;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class NotifierService {

    // ALLOWED_PLACEHOLDERS mapping based on Python's notifier.py
    protected const ALLOWED_PLACEHOLDERS = [
        "customer_name", "customer_document", "customer_email",
        "receivable_number", "nosso_numero", "due_date",
        "amount_total", "balance_amount", "status"
    ];

    public static function formatMoney($value): string {
        if ($value === null) return "";
        return number_format((float)$value, 2, ',', '.');
    }

    public static function formatDate($value): string {
        if (empty($value)) return "";
        return date("d/m/Y", strtotime($value));
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

    public static function dispatchPendingOutbox(int $companyId, int $limit = 20): array {
        $limit = max(1, min(200, $limit));

        $messages = OutboxMessage::where('company_id', $companyId)
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc')
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
