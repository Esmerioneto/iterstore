<?php

declare(strict_types=1);

namespace Iter\Message\Model;

use Iter\Core\Db\Connection;
use Iter\Lgpd\Model\PersonalDataInterface;

/** LGPD for the messages with the store (Iter_Lgpd calls it, see etc/lgpd.json): listed in the copy, deleted on erase */
final class PersonalData implements PersonalDataInterface
{
    public function export(?int $customerId, string $email): array
    {
        if ($customerId === null) {
            return [];
        }

        return array_map(static fn ($m) => substr((string) $m['created_at'], 0, 16) . ' — ' . ($m['sender'] === 'customer' ? 'Você' : 'Loja') . ': ' . $m['body'],
            Connection::get()->adapter()->fetchAll('SELECT sender, body, created_at FROM customer_message WHERE customer_id = :c ORDER BY message_id', ['c' => $customerId]));
    }

    public function blockers(int $customerId): array
    {
        return [];
    }

    public function erase(int $customerId, string $email): void
    {
        Connection::get()->adapter()->delete('customer_message', ['customer_id = ?' => $customerId]);
    }
}
