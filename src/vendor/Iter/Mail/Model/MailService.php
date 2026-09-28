<?php

declare(strict_types=1);

namespace Iter\Mail\Model;

use Iter\Core\Config;
use Iter\Core\Crypt;
use Iter\Core\Db\Connection;
use PDO;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The one way to send e-mail in the system:
 *
 *     (new MailService())->send('admin_password_reset', $email, ['name' => ..., 'link' => ...], now: true);
 *
 * The message is rendered from its template and stored in mail_queue; the
 * worker (bin/mail-worker.php, cron every minute) sends it, retrying with
 * back-off. `now: true` tries right away (someone is waiting on the screen)
 * and leaves it queued if that fails.
 */
class MailService
{
    public const PENDING = 0;
    public const SENT = 1;
    public const FAILED = 2;
    public const CANCELED = 3;
    public const STATUS_LABELS = [self::PENDING => 'Pendente', self::SENT => 'Enviado', self::FAILED => 'Falhou', self::CANCELED => 'Cancelado'];

    private const BACKOFF_MINUTES = [1, 5, 15, 60];
    // a claimed message is retried by another worker if this one dies mid-send
    private const LEASE = "INTERVAL '10 minutes'";

    /**
     * @param string|string[] $to
     * @param array $options cc, bcc, reply_to (string|string[]), attachments (file paths, copied), website_id, from ([email, name])
     * @return int the queued message's id; 0 when the per-recipient hourly limit was reached (logged)
     */
    public function send(string $template, string|array $to, array $vars = [], array $options = [], bool $now = false): int
    {
        $websiteId = isset($options['website_id']) ? (int) $options['website_id'] : null;
        $recipients = [
            'to' => self::addresses($to),
            'cc' => self::addresses($options['cc'] ?? []),
            'bcc' => self::addresses($options['bcc'] ?? []),
            'reply_to' => self::addresses($options['reply_to'] ?? (Config::get('mail/reply_to', $websiteId) ?: [])),
        ];
        if (!$recipients['to']) {
            throw new MailException('RECIPIENT_INVALID', 'Informe ao menos um destinatário.');
        }

        $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM mail_queue WHERE to_email = :to AND template_code = :code AND created_at > NOW() - INTERVAL '1 hour'");
        $stmt->execute(['to' => $recipients['to'][0], 'code' => $template]);
        if ((int) $stmt->fetchColumn() >= (int) Config::get('mail/limit_per_hour')) {
            error_log("Mail: limite por hora atingido para {$recipients['to'][0]} ({$template}); e-mail não enfileirado");
            return 0;
        }

        [$fromEmail, $fromName] = $options['from'] ?? $this->sender($websiteId);
        $definition = Template::get($template);
        $mail = Template::render($definition, $vars + ['store_name' => $fromName]);

        $stmt = $this->pdo()->prepare(
            'INSERT INTO mail_queue (template_code, website_id, to_email, recipients, from_email, from_name, subject, body_html, body_text, attachments, is_private)
             VALUES (:code, :website, :to, :recipients, :from_email, :from_name, :subject, :html, :text, :attachments, :private) RETURNING message_id'
        );
        $stmt->execute([
            'code' => $template,
            'website' => $websiteId,
            'to' => $recipients['to'][0],
            'recipients' => json_encode($recipients),
            'from_email' => $fromEmail === '' ? throw new MailException('SENDER_INVALID', 'Configure o e-mail do remetente (Configurações → E-mail).') : self::addresses($fromEmail)[0],
            'from_name' => str_replace(["\r", "\n"], ' ', (string) $fromName),
            'subject' => $mail['subject'],
            'html' => $mail['html'],
            'text' => $mail['text'],
            'attachments' => json_encode(array_map([$this, 'keepAttachment'], $options['attachments'] ?? [])),
            'private' => $definition['private'] ? 1 : 0,
        ]);
        $id = (int) $stmt->fetchColumn();

        if ($now && Config::get('mail/transport') !== 'disabled') {
            $claim = $this->pdo()->prepare('UPDATE mail_queue SET send_after = NOW() + ' . self::LEASE . ' WHERE message_id = :id AND status = 0 RETURNING *');
            $claim->execute(['id' => $id]);
            $this->deliver($claim->fetch(PDO::FETCH_ASSOC));
        }

        return $id;
    }

    /**
     * Worker: sends due messages, then drops history older than the retention.
     * Claims with SKIP LOCKED, so two workers never send the same message.
     *
     * @return array{sent: int, failed: int}
     */
    public function processQueue(int $limit = 50): array
    {
        $result = ['sent' => 0, 'failed' => 0];
        if (Config::get('mail/transport') !== 'disabled') {
            $claim = $this->pdo()->prepare(
                'UPDATE mail_queue SET send_after = NOW() + ' . self::LEASE . ' WHERE message_id IN (
                    SELECT message_id FROM mail_queue WHERE status = 0 AND send_after <= NOW() ORDER BY message_id LIMIT :limit FOR UPDATE SKIP LOCKED
                 ) RETURNING *'
            );
            $claim->execute(['limit' => $limit]);
            foreach ($claim->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[$this->deliver($row) ? 'sent' : 'failed']++;
            }
        }

        $old = $this->pdo()->prepare('DELETE FROM mail_queue WHERE status <> 0 AND created_at < NOW() - make_interval(days => :days) RETURNING message_id, attachments');
        $old->execute(['days' => (int) Config::get('mail/log/retention_days')]);
        foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $this->removeFiles($row);
        }

        return $result;
    }

    /** Only a pending message can be canceled */
    public function cancel(int $id): bool
    {
        $stmt = $this->pdo()->prepare('UPDATE mail_queue SET status = 3 WHERE message_id = :id AND status = 0');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /** Queues a copy of a sent/failed/canceled message; private ones (their body is gone) can't be resent */
    public function resend(int $id): int
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO mail_queue (template_code, website_id, to_email, recipients, from_email, from_name, subject, body_html, body_text, attachments, is_private)
             SELECT template_code, website_id, to_email, recipients, from_email, from_name, subject, body_html, body_text, attachments, is_private
             FROM mail_queue WHERE message_id = :id AND is_private = 0 AND status <> 0 RETURNING message_id'
        );
        $stmt->execute(['id' => $id]);

        return (int) $stmt->fetchColumn() ?: throw new MailException('RESEND_BLOCKED', 'Este e-mail não pode ser reenviado.');
    }

    /**
     * History, newest first. Filters: status, q (recipient, part of it), from/to (Y-m-d).
     *
     * @return array{items: array, total: int}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        if (($filters['status'] ?? '') !== '' && isset(self::STATUS_LABELS[(int) $filters['status']])) {
            $where[] = 'status = :status';
            $params['status'] = (int) $filters['status'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = "to_email LIKE :q ESCAPE '\\'";
            $params['q'] = '%' . addcslashes(mb_strtolower($filters['q']), '%_\\') . '%';
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key] ?? '')) {
                $where[] = "created_at {$op} CAST(:{$key} AS DATE)" . ($key === 'to' ? " + 1" : '');
                $params[$key] = $filters[$key];
            }
        }
        $sql = ' FROM mail_queue' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

        $count = $this->pdo()->prepare('SELECT COUNT(*)' . $sql);
        $count->execute($params);
        $items = $this->pdo()->prepare(
            'SELECT message_id, template_code, to_email, subject, status, attempts, created_at, sent_at' . $sql
            . ' ORDER BY message_id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $items->execute($params);

        return ['items' => $items->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $count->fetchColumn()];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM mail_queue WHERE message_id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Pending for more than 15 minutes: the worker (cron) is probably not running */
    public function stalledCount(): int
    {
        return (int) $this->pdo()->query("SELECT COUNT(*) FROM mail_queue WHERE status = 0 AND created_at < NOW() - INTERVAL '15 minutes'")->fetchColumn();
    }

    public static function logDir(): string
    {
        return dirname(SRC) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'mail' . DIRECTORY_SEPARATOR;
    }

    /** Sends one claimed message and records the outcome */
    private function deliver(array $row): bool
    {
        $recipients = json_decode($row['recipients'], true);
        try {
            $email = (new Email())
                ->from(new Address($row['from_email'], (string) $row['from_name']))
                ->to(...$recipients['to'])->cc(...$recipients['cc'])->bcc(...$recipients['bcc'])->replyTo(...$recipients['reply_to'])
                ->subject($row['subject'])
                ->html($row['body_html'])
                ->text($row['body_text']);
            foreach (json_decode($row['attachments'], true) as $file) {
                $email->attachFromPath($file['path'], $file['name']);
            }

            if (Config::get('mail/transport') === 'log') {
                is_dir(self::logDir()) || mkdir(self::logDir(), 0775, true);
                file_put_contents(self::logDir() . $row['message_id'] . '.eml', $email->toString());
            } else {
                $this->smtp($row['website_id'] === null ? null : (int) $row['website_id'])->send($email);
            }
        } catch (\Throwable $e) {
            $attempts = (int) $row['attempts'] + 1;
            $failed = $attempts >= (int) Config::get('mail/queue/max_attempts');
            $this->pdo()->prepare(
                'UPDATE mail_queue SET attempts = :attempts, status = :status, last_error = :error, send_after = NOW() + make_interval(mins => :wait) WHERE message_id = :id'
            )->execute([
                'id' => $row['message_id'],
                'attempts' => $attempts,
                'status' => $failed ? self::FAILED : self::PENDING,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'wait' => self::BACKOFF_MINUTES[min($attempts, count(self::BACKOFF_MINUTES)) - 1],
            ]);
            error_log("Mail: mensagem {$row['message_id']} não enviada (tentativa {$attempts}): " . $e->getMessage());

            return false;
        }

        // a private message (password reset link) keeps no body once delivered
        $this->pdo()->prepare(
            'UPDATE mail_queue SET status = 1, attempts = attempts + 1, sent_at = NOW(), last_error = NULL'
            . ($row['is_private'] ? ', body_html = NULL, body_text = NULL' : '') . ' WHERE message_id = :id'
        )->execute(['id' => $row['message_id']]);

        return true;
    }

    /** Website's SMTP (each setting falls back to the default); no host saved = src/etc/mail.ini */
    private function smtp(?int $websiteId): EsmtpTransport
    {
        $host = (string) Config::get('mail/smtp/host', $websiteId);
        $ini = $host === '' && is_file(ETC . 'mail.ini') ? parse_ini_file(ETC . 'mail.ini') : [];
        $smtp = $host !== '' ? [
            'host' => $host,
            'port' => (int) Config::get('mail/smtp/port', $websiteId),
            'encryption' => Config::get('mail/smtp/encryption', $websiteId),
            'username' => (string) Config::get('mail/smtp/username', $websiteId),
            'password' => Crypt::decrypt(Config::get('mail/smtp/password', $websiteId)),
        ] : [
            'host' => $ini['host'] ?? throw new MailException('SMTP_NOT_CONFIGURED', 'Servidor SMTP não configurado (Configurações → E-mail).'),
            'port' => (int) ($ini['port'] ?? 587),
            'encryption' => 'tls',
            'username' => (string) ($ini['username'] ?? ''),
            'password' => (string) ($ini['password'] ?? ''),
        ];

        $transport = new EsmtpTransport($smtp['host'], $smtp['port'], $smtp['encryption'] === 'ssl');
        $transport->setAutoTls($smtp['encryption'] !== 'none');
        $transport->getStream()->setTimeout(10);
        if ($smtp['username'] !== '') {
            $transport->setUsername($smtp['username'])->setPassword($smtp['password']);
        }

        return $transport;
    }

    /** @return array{0: string, 1: string} the website's sender, falling back to the default and then to mail.ini */
    private function sender(?int $websiteId): array
    {
        $ini = is_file(ETC . 'mail.ini') ? parse_ini_file(ETC . 'mail.ini') : [];

        return [
            Config::get('mail/from_email', $websiteId) ?: ($ini['from_email'] ?? ''),
            Config::get('mail/from_name', $websiteId) ?: ($ini['from_name'] ?? ''),
        ];
    }

    /**
     * Valid addresses only: a line break or anything else that could inject a header is refused.
     *
     * @param string|string[] $emails
     * @return string[]
     */
    private static function addresses(string|array $emails): array
    {
        return array_map(static function ($email): string {
            $email = trim((string) $email);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
                throw new MailException('RECIPIENT_INVALID', "Endereço de e-mail inválido: {$email}");
            }

            return mb_strtolower($email);
        }, array_values((array) $emails));
    }

    /** Copies an attachment into var/mail/attachments, so it's still there when the worker sends it */
    private function keepAttachment(string $path): array
    {
        $dir = self::logDir() . 'attachments' . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . DIRECTORY_SEPARATOR;
        if (!is_file($path) || !mkdir($dir, 0775, true) || !copy($path, $dir . basename($path))) {
            throw new MailException('ATTACHMENT_INVALID', 'Anexo não encontrado: ' . basename($path));
        }

        return ['path' => $dir . basename($path), 'name' => basename($path)];
    }

    private function removeFiles(array $row): void
    {
        @unlink(self::logDir() . $row['message_id'] . '.eml');
        foreach (json_decode($row['attachments'], true) as $file) {
            @unlink($file['path']);
            @rmdir(dirname($file['path']));
        }
    }

    private function pdo(): PDO
    {
        return Connection::get()->connect();
    }
}
