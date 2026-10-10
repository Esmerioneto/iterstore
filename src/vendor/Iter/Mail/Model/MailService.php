<?php

declare(strict_types=1);

namespace Iter\Mail\Model;

use Iter\Core\Config;
use Iter\Core\Crypt;
use Iter\Core\Db\Adapter;
use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Core\Db\Select;
use Iter\Core\Log;
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

    public const LOGO_CID = 'store-logo';

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

        $db = $this->db();
        $sentLastHour = $db->fetchOne($db->select()->from('mail_queue', ['COUNT(*)'])
            ->where('to_email = ?', $recipients['to'][0])
            ->where('template_code = ?', $template)
            ->where("created_at > NOW() - INTERVAL '1 hour'"));
        if ((int) $sentLastHour >= (int) Config::get('mail/limit_per_hour')) {
            Log::notify("Mail: limite por hora atingido para {$recipients['to'][0]} ({$template}); e-mail não enfileirado");
            return 0;
        }

        [$fromEmail, $fromName] = $options['from'] ?? $this->sender($websiteId);
        $definition = Template::get($template);
        $mail = Template::render($definition, $vars + ['store_name' => $fromName, 'logo' => self::logoRow($websiteId, (string) $fromName)]);

        $id = (int) $db->insert('mail_queue', [
            'template_code' => $template,
            'website_id' => $websiteId,
            'to_email' => $recipients['to'][0],
            'recipients' => json_encode($recipients),
            'from_email' => $fromEmail === '' ? throw new MailException('SENDER_INVALID', 'Configure o e-mail do remetente (Configurações → E-mail).') : self::addresses($fromEmail)[0],
            'from_name' => str_replace(["\r", "\n"], ' ', (string) $fromName),
            'subject' => $mail['subject'],
            'body_html' => $mail['html'],
            'body_text' => $mail['text'],
            'attachments' => json_encode(array_map([$this, 'keepAttachment'], $options['attachments'] ?? [])),
            'is_private' => $definition['private'] ? 1 : 0,
        ])['message_id'];

        if ($now && Config::get('mail/transport') !== 'disabled') {
            $claimed = $this->claim($db->select()->from('mail_queue', ['message_id'])->where('message_id = ?', $id)->where('status = 0'));
            $claimed && $this->deliver($claimed[0]);
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
            $due = $this->db()->select()->from('mail_queue', ['message_id'])->where('status = 0')->where('send_after <= NOW()')->order('message_id')->limit($limit);
            foreach ($this->claim($due) as $row) {
                $result[$this->deliver($row) ? 'sent' : 'failed']++;
            }
        }

        $db = $this->db();
        $old = $db->fetchAll($db->select()->from('mail_queue', ['message_id', 'attachments'])
            ->where('status <> 0')
            ->where('created_at < NOW() - make_interval(days => ?)', (int) Config::get('mail/log/retention_days')));
        $db->delete('mail_queue', ['message_id IN (?)' => array_column($old, 'message_id')]);
        foreach ($old as $row) {
            $this->removeFiles($row);
        }

        return $result;
    }

    /** Only a pending message can be canceled */
    public function cancel(int $id): bool
    {
        return $this->db()->update('mail_queue', ['status' => self::CANCELED], ['message_id = ?' => $id, 'status = 0']) === 1;
    }

    /** Queues a copy of a sent/failed/canceled message; private ones (their body is gone) can't be resent */
    public function resend(int $id): int
    {
        $db = $this->db();
        $copy = $db->fetchRow($db->select()
            ->from('mail_queue', ['template_code', 'website_id', 'to_email', 'recipients', 'from_email', 'from_name', 'subject', 'body_html', 'body_text', 'attachments', 'is_private'])
            ->where('message_id = ?', $id)->where('is_private = 0')->where('status <> 0'));

        return $copy ? (int) $db->insert('mail_queue', $copy)['message_id'] : throw new MailException('RESEND_BLOCKED', 'Este e-mail não pode ser reenviado.');
    }

    /**
     * History, newest first. Filters: status, q (recipient, part of it), from/to (Y-m-d).
     *
     * @return array{items: array, total: int}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $db = $this->db();
        $select = $db->select()->from('mail_queue', ['COUNT(*)']);
        if (($filters['status'] ?? '') !== '' && isset(self::STATUS_LABELS[(int) $filters['status']])) {
            $select->where('status = ?', (int) $filters['status']);
        }
        if (($filters['q'] ?? '') !== '') {
            $select->where("to_email LIKE ? ESCAPE '\\'", '%' . addcslashes(mb_strtolower($filters['q']), '%_\\') . '%');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from'] ?? '')) {
            $select->where('created_at >= CAST(? AS DATE)', $filters['from']);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to'] ?? '')) {
            $select->where('created_at < CAST(? AS DATE) + 1', $filters['to']);
        }
        $total = (int) $db->fetchOne($select);
        $select->reset(Select::COLUMNS)
            ->columns(['message_id', 'template_code', 'to_email', 'subject', 'status', 'attempts', 'created_at', 'sent_at'])
            ->order('message_id DESC')->limitPage($page, $perPage);

        return ['items' => $db->fetchAll($select), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        return $this->db()->fetchRow($this->db()->select()->from('mail_queue')->where('message_id = ?', $id)) ?: null;
    }

    /** Pending for more than 15 minutes: the worker (cron) is probably not running */
    public function stalledCount(): int
    {
        return (int) $this->db()->fetchOne($this->db()->select()->from('mail_queue', ['COUNT(*)'])
            ->where('status = 0')->where("created_at < NOW() - INTERVAL '15 minutes'"));
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
            // the logo goes inside the message: it shows without a public address or "load images"
            if (str_contains((string) $row['body_html'], 'cid:' . self::LOGO_CID)) {
                $logo = self::logoPath($row['website_id'] === null ? null : (int) $row['website_id']);
                $logo === null ? $email->html(str_replace('cid:' . self::LOGO_CID, '', $row['body_html'])) : $email->embedFromPath($logo, self::LOGO_CID);
            }
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
            $this->db()->update('mail_queue', [
                'attempts' => $attempts,
                'status' => $failed ? self::FAILED : self::PENDING,
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
                'send_after' => new Expr('NOW() + make_interval(mins => ' . self::BACKOFF_MINUTES[min($attempts, count(self::BACKOFF_MINUTES)) - 1] . ')'),
            ], ['message_id = ?' => $row['message_id']]);
            Log::error("Mail: mensagem {$row['message_id']} não enviada (tentativa {$attempts}): " . $e->getMessage());

            return false;
        }

        // a private message (password reset link) keeps no body once delivered
        $this->db()->update('mail_queue', [
            'status' => self::SENT,
            'attempts' => new Expr('attempts + 1'),
            'sent_at' => new Expr('NOW()'),
            'last_error' => null,
        ] + ($row['is_private'] ? ['body_html' => null, 'body_text' => null] : []), ['message_id = ?' => $row['message_id']]);

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
    /** the website's logo (Configurações → Loja, the app's storeInfo.logoUrl); null without one */
    public static function logoPath(?int $websiteId): ?string
    {
        $value = (string) Config::get('store/info/logo', $websiteId);
        $path = Config::mediaDir() . $value;

        return $value !== '' && is_file($path) ? $path : null;
    }

    /** the layout's {{{ logo }}}: a row with the logo above the header; empty without one */
    private static function logoRow(?int $websiteId, string $alt): string
    {
        return self::logoPath($websiteId) === null ? '' : '<tr><td align="center" style="padding:24px 32px;"><img src="cid:' . self::LOGO_CID
            . '" alt="' . htmlspecialchars($alt) . '" style="display:block; max-width:200px; max-height:80px; border:0;"></td></tr>';
    }

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

    /**
     * Leases the messages $ids selects (message_id) so no other worker takes them:
     * locked with SKIP LOCKED, pushed past the lease, committed before sending.
     *
     * @return array<int, array<string, mixed>> the claimed rows
     */
    private function claim(Select $ids): array
    {
        return $this->db()->transaction(static function (Adapter $db) use ($ids): array {
            $claimed = $db->fetchCol($ids->forUpdate(true));
            if (!$claimed) {
                return [];
            }
            $db->update('mail_queue', ['send_after' => new Expr('NOW() + ' . self::LEASE)], ['message_id IN (?)' => $claimed]);

            return $db->fetchAll($db->select()->from('mail_queue')->where('message_id IN (?)', $claimed)->order('message_id'));
        });
    }

    private function db(): Adapter
    {
        return Connection::get()->adapter();
    }
}
