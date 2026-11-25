<?php

declare(strict_types=1);

namespace Iter\Mail\Model;

use Iter\Core\Db\Connection;
use Iter\Lgpd\Model\PersonalDataInterface;

/**
 * LGPD for the e-mails sent (Iter_Lgpd calls it, see etc/lgpd.json). The copy lists date and subject; erase empties
 * the address and the content of what already left. E-mails still waiting (the erase confirmation, queued just
 * before) go out as they are.
 */
final class PersonalData implements PersonalDataInterface
{
    public const ERASED = 'apagado (LGPD)';

    public function export(?int $customerId, string $email): array
    {
        return array_map(static fn ($m) => substr((string) ($m['sent_at'] ?? $m['created_at']), 0, 16) . ' — ' . $m['subject'],
            Connection::get()->adapter()->fetchAll('SELECT subject, sent_at, created_at FROM mail_queue WHERE LOWER(to_email) = :e ORDER BY message_id', ['e' => mb_strtolower($email)]));
    }

    public function blockers(int $customerId): array
    {
        return [];
    }

    public function erase(int $customerId, string $email): void
    {
        Connection::get()->adapter()->update('mail_queue', ['to_email' => self::ERASED, 'recipients' => '[]', 'body_html' => '', 'body_text' => '', 'headers' => '{}', 'attachments' => '[]'],
            ['LOWER(to_email) = ?' => mb_strtolower($email), 'status <> ?' => MailService::PENDING]);
    }
}
