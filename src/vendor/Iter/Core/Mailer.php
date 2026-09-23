<?php

declare(strict_types=1);

namespace Iter\Core;

/**
 * Minimal SMTP client (EHLO, STARTTLS, AUTH LOGIN) reading src/etc/mail.ini.
 * send() returns false and logs instead of throwing, so a mail outage never
 * breaks the page that triggered it.
 */
class Mailer
{
    // ponytail: plain-text, one recipient, no queue; switch to symfony/mailer when HTML mails or attachments show up
    private const TIMEOUT = 10;

    /** @var resource|null */
    private $socket = null;

    public static function send(string $to, string $subject, string $body): bool
    {
        $config = is_file(ETC . 'mail.ini') ? parse_ini_file(ETC . 'mail.ini') : false;
        if (!$config || empty($config['host']) || ($config['password'] ?? '') === '') {
            error_log('Mailer: src/etc/mail.ini ausente ou sem senha; e-mail para ' . $to . ' não enviado');
            return false;
        }

        $mailer = new self();
        try {
            $mailer->deliver($config, $to, $subject, $body);
            return true;
        } catch (\RuntimeException $e) {
            error_log('Mailer: ' . $e->getMessage());
            return false;
        } finally {
            if ($mailer->socket) {
                fclose($mailer->socket);
            }
        }
    }

    private function deliver(array $config, string $to, string $subject, string $body): void
    {
        $this->socket = @stream_socket_client(
            "tcp://{$config['host']}:{$config['port']}", $errno, $errstr, self::TIMEOUT
        ) ?: throw new \RuntimeException("conexão com {$config['host']}:{$config['port']} falhou: {$errstr}");
        stream_set_timeout($this->socket, self::TIMEOUT);

        $this->expect(220);
        $this->command('EHLO iterstore', 250);
        $this->command('STARTTLS', 220);
        if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new \RuntimeException('falha ao iniciar TLS');
        }
        $this->command('EHLO iterstore', 250);
        $this->command('AUTH LOGIN', 334);
        $this->command(base64_encode($config['username']), 334);
        $this->command(base64_encode($config['password']), 235, 'AUTH (senha omitida)');

        $from = $config['from_email'];
        $this->command("MAIL FROM:<{$from}>", 250);
        $this->command('RCPT TO:<' . self::address($to) . '>', 250);
        $this->command('DATA', 354);

        $headers = implode("\r\n", [
            'Date: ' . date('r'),
            'From: ' . mb_encode_mimeheader($config['from_name'] ?? $from) . " <{$from}>",
            'To: <' . self::address($to) . '>',
            'Subject: ' . mb_encode_mimeheader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ]);
        // base64 body: no dot-stuffing or line-length issues
        $this->command($headers . "\r\n\r\n" . chunk_split(base64_encode($body)) . '.', 250, 'DATA (conteúdo)');
        $this->command('QUIT', 221);
    }

    /** Rejects anything that could inject SMTP commands or headers. */
    private static function address(string $email): string
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) ?: throw new \RuntimeException('destinatário inválido');
    }

    private function command(string $line, int $expected, ?string $label = null): void
    {
        fwrite($this->socket, $line . "\r\n");
        $this->expect($expected, $label ?? strtok($line, "\r\n"));
    }

    private function expect(int $code, string $after = 'conexão'): void
    {
        $reply = '';
        // multi-line replies are "250-..." until the last "250 ..."
        while (($line = fgets($this->socket, 1024)) !== false) {
            $reply .= $line;
            if (($line[3] ?? ' ') === ' ') {
                break;
            }
        }

        if ((int) substr($reply, 0, 3) !== $code) {
            throw new \RuntimeException("resposta inesperada após {$after}: " . trim($reply ?: 'sem resposta'));
        }
    }
}
