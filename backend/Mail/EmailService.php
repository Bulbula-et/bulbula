<?php

declare(strict_types=1);

namespace Bulbula\Backend\Mail;

use Bulbula\Backend\Core\Config;
use RuntimeException;

/**
 * Provider-independent transactional email service.
 *
 * Uses the transport configured in `config/app.php` under `'mail' . 'transport'`.
 * Currently supports:
 * - 'brevo'  — Brevo SMTP or API (initial choice per D-41)
 * - 'smtp'   — authenticated SMTP via config SMTP settings
 * - 'log'    — development-only log transport (LocalFileMailer when APP_ENV=local)
 *
 * Credentials are read from the configuration / environment and must never be
 * committed to source.  The class is in `Backend\\Mail`, not `Backend\\Integrations`,
 * because the transport choice is a D-41 decision that does not require a full
 * integration layer until a second provider is added.
 */
final class EmailService implements Mailer
{
    private readonly Config $config;
    private readonly string $transport;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->transport = $config->string('mail.transport', 'brevo');
    }

    /** @throws RuntimeException when the message could not be delivered */
    public function send(Message $message): void
    {
        switch ($this->transport) {
            case 'brevo':
                $this->sendBrevo($message);
                break;
            case 'smtp':
                $this->sendSmtp($message);
                break;
            case 'log':
                $this->sendLog($message);
                break;
            default:
                throw new RuntimeException('Unsupported mail transport: ' . $this->transport);
        }
    }

    private function sendBrevo(Message $message): void
    {
        $apiKey = $this->config->string('mail.brevo.api_key');
        $senderName = $this->config->string('mail.brevo.sender_name', 'Bulbula');
        $senderEmail = $this->config->string('mail.brevo.sender_email', '');

        if ($apiKey === '') {
            throw new RuntimeException('Brevo API key not configured; cannot send email.');
        }

        $url = 'https://api.brevo.com/v3/smtp/email';

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize cURL for Brevo API.');
        }

        $recipients = [];
        if (preg_match('/^(.+)\s+<(.+)>$/', $message->to, $m)) {
            $recipients[] = ['email' => $m[2], 'name' => $m[1]];
        } else {
            // Try plain email address
            if (filter_var($message->to, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = ['email' => $message->to];
            } else {
                throw new RuntimeException('Invalid recipient email address: ' . $message->to);
            }
        }

        $payload = [
            'sender' => [
                'name'  => $senderName,
                'email' => $senderEmail,
            ],
            'to' => $recipients,
            'subject' => $message->subject,
            'htmlContent' => $message->body, // Brevo expects HTML; we send plain text which it handles
            'textContent' => $message->body,
        ];

        $jsonPayload = json_encode($payload);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('Brevo API request failed (HTTP ' . ($httpCode ?? 'unknown') . '): ' . ($response ?? 'no response'));
        }
    }

    private function sendSmtp(Message $message): void
    {
        $host = $this->config->string('mail.smtp.host');
        $port = (int) $this->config->string('mail.smtp.port', 587);
        $username = $this->config->string('mail.smtp.username');
        $password = $this->config->string('mail.smtp.password');
        $encryption = $this->config->string('mail.smtp.encryption', 'tls');

        if ($host === '' || $username === '' || $password === '') {
            throw new RuntimeException('SMTP transport selected but SMTP credentials are not fully configured.');
        }

        $headers = "From: {$username}\r\n";
        $headers .= "Reply-To: {$username}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-transfer-encoding: 7bit\r\n";
        $headers .= "Content-type: text/plain; charset=UTF-8\r\n";

        $smtpConn = fsockopen(
            $host,
            $port,
            $errno,
            $errstr,
            30
        );

        if (!$smtpConn) {
            throw new RuntimeException("SMTP connection failed: $errstr ($errno)");
        }

        // Read greeting
        $response = fgets($smtpConn, 256);
        if (!preg_match('/^220/', $response)) {
            fclose($smtpConn);
            throw new RuntimeException('SMTP greeting failed: ' . $response);
        }

        // EHLO
        fputs($smtpConn, 'EHLO ' . $host . "\r\n");
        $response = fgets($smtpConn, 256);
        if (!preg_match('/^250/', $response)) {
            fclose($smtpConn);
            throw new RuntimeException('SMTP EHLO failed: ' . $response);
        }

        // Start TLS
        if ($encryption === 'tls') {
            fputs($smtpConn, 'STARTTLS\r\n');
            $response = fgets($smtpConn, 256);
            if (!preg_match('/^220/', $response)) {
                fclose($smtpConn);
                throw new RuntimeException('SMTP STARTTLS failed: ' . $response);
            }
            // Re-negotiate after TLS
            fputs($smtpConn, 'EHLO ' . $host . "\r\n");
            $response = fgets($smtpConn, 256);
            if (!preg_match('/^250/', $response)) {
                fclose($smtpConn);
                throw new RuntimeException('SMTP EHLO after TLS failed: ' . $response);
            }
        }

        // AUTH LOGIN
        fputs($smtpConn, 'AUTH LOGIN\r\n');
        $response = fgets($smtpConn, 256);
        if (!preg_match('/^334/', $response)) {
            fclose($smtpConn);
            throw new RuntimeException('SMTP AUTH failed: ' . $response);
        }

        // Send username
        fputs($smtpConn, base64_encode($username) . "\r\n");
        $response = fgets($smtpConn, 256);
        if (!preg_match('/^334/', $response)) {
            fclose($smtpConn);
            throw new RuntimeException('SMTP AUTH username failed: ' . $response);
        }

        // Send password
        fputs($smtpConn, base64_encode($password) . "\r\n");
        $response = fgets($smtpConn, 256);
        if (!preg_match('/^235/', $response) && !preg_match('/^237/', $response)) {
            fclose($smtpConn);
            throw new RuntimeException('SMTP AUTH password failed: ' . $response);
        }

        // MAIL FROM
        fputs($smtpConn, 'MAIL FROM:<' . $username . '>' . "\r\n");
        $response = fgets($smtpConn, 256);
        if (!preg_match('/^250/', $response)) {
            fclose($smtpConn);
            throw new RuntimeException('SMTP MAIL FROM failed: ' . $response);
        }

        // RCPT TO
        fputs($smtpConn, 'RCPT TO:<' . $message->to . '>' . "\r\n");
        $response = fgets($smtpConn, 256);
        if (!preg_match('/^250/', $response)) {
            fclose($smtpConn);
            throw new RuntimeException('SMTP RCPT TO failed: ' . $response);
        }

        // DATA
        fputs($smtpConn, 'DATA\r\n');
        $response = fgets($smtpConn, 256);
        if (!preg_match('/^354/', $response)) {
            fclose($smtpConn);
            throw new RuntimeException('SMTP DATA failed: ' . $response);
        }

        // Build message
        $body = "To: {$message->to}\r\n";
        $body .= "Subject: {$message->subject}\r\n";
        $headers = "X-Mailer: Bulbula\r\n";
        $body .= $headers . "\r\n";
        $body .= $message->body . "\r\n";
        $body .= "." . "\r\n";

        fputs($smtpConn, $body . "\r.\r\n");
        $response = fgets($smtpConn, 256);
        if (!preg_match('/^250/', $response)) {
            fclose($smtpConn);
            throw new RuntimeException('SMTP DATA message failed: ' . $response);
        }

        // QUIT
        fputs($smtpConn, 'QUIT\r\n');
        $response = fgets($smtpConn, 256);
        fclose($smtpConn);

        if (!preg_match('/^221/', $response)) {
            throw new RuntimeException('SMTP QUIT failed: ' . $response);
        }
    }

    private function sendLog(Message $message): void
    {
        $logPath = $this->config->string('mail.log.path', 'storage/tmp/mail');

        if (!is_dir($logPath) && !mkdir($logPath, 0770, true) && !is_dir($logPath)) {
            throw new RuntimeException("Could not create the mail log directory [$logPath].");
        }

        $microseconds = (int) floor(fmod(microtime(true), 1.0) * 1_000_000);
        $name = gmdate('Ymd-His') . '-' . sprintf('%06d', $microseconds) . '-' . bin2hex(random_bytes(4)) . '.txt';

        $written = file_put_contents(
            rtrim($logPath, '/') . '/' . $name,
            "To: {$message->to}\nSubject: {$message->subject}\n\n{$message->body}\n"
        );

        if ($written === false) {
            throw new RuntimeException("Could not write mail log file in [$logPath].");
        }
    }
}