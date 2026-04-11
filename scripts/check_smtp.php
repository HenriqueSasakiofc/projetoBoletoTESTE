<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$recipient = $argv[1] ?? ($_ENV['SMTP_FROM_EMAIL'] ?? null);
if (!$recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Informe um e-mail de teste valido: php scripts/check_smtp.php seu@email.com\n");
    exit(1);
}

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = trim((string) ($_ENV['SMTP_HOST'] ?? ''));
    $mail->SMTPAuth = !empty($_ENV['SMTP_USERNAME']);
    $mail->Username = trim((string) ($_ENV['SMTP_USERNAME'] ?? ''));
    $mail->Password = trim((string) ($_ENV['SMTP_PASSWORD'] ?? ''));
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int) ($_ENV['SMTP_PORT'] ?? 587);
    $mail->CharSet = 'UTF-8';
    $mail->setFrom(trim((string) ($_ENV['SMTP_FROM_EMAIL'] ?? '')), $_ENV['SMTP_FROM_NAME'] ?? 'Projeto Boleto');
    $mail->addAddress($recipient);
    $mail->Subject = 'Teste SMTP Projeto Boleto';
    $mail->Body = 'Se voce recebeu este e-mail, o SMTP do Projeto Boleto esta funcionando.';
    $mail->send();

    echo json_encode([
        'status' => 'ok',
        'message' => 'SMTP autenticado e e-mail de teste enviado.',
        'recipient' => $recipient,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    $error = $mail->ErrorInfo ?: $e->getMessage();
    if (stripos($error, 'Could not authenticate') !== false) {
        $error .= ' Use uma senha de app do Gmail, nao a senha normal da conta.';
    }

    echo json_encode([
        'status' => 'error',
        'message' => $error,
        'host' => $_ENV['SMTP_HOST'] ?? null,
        'port' => $_ENV['SMTP_PORT'] ?? null,
        'username' => $_ENV['SMTP_USERNAME'] ?? null,
        'password_is_set' => !empty($_ENV['SMTP_PASSWORD']),
        'password_length' => strlen((string) ($_ENV['SMTP_PASSWORD'] ?? '')),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
