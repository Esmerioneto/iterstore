<?php

declare(strict_types=1);

namespace Iter\Message\Model;

use Iter\Core\Db\Connection;
use Iter\Eav\Model\AttributeException;

/**
 * The customer's conversation with the store (MESSAGE-PLAN): one per customer, text only.
 * The admin screens and the API call the same rules here.
 */
final class MessageService
{
    public const MAX_LENGTH = 2000;
    public const PAGE_SIZE = 20;

    /**
     * @param string $sender 'customer' or 'admin'
     * @return array the stored message, as thread() gives it
     * @throws AttributeException MESSAGE_EMPTY, MESSAGE_TOO_LONG, CUSTOMER_NOT_FOUND
     */
    public function send(int $customerId, string $sender, string $body, ?int $adminUserId = null): array
    {
        $body = trim($body);
        if ($body === '') {
            throw new AttributeException('MESSAGE_EMPTY', 'Escreva a mensagem.');
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new AttributeException('MESSAGE_TOO_LONG', 'A mensagem pode ter até ' . self::MAX_LENGTH . ' caracteres.');
        }
        $db = Connection::get()->adapter();
        $websiteId = $db->fetchOne('SELECT website_id FROM customer_entity WHERE customer_id = :id', ['id' => $customerId]);
        if ($websiteId === false) {
            throw new AttributeException('CUSTOMER_NOT_FOUND', 'Cliente não encontrado.');
        }
        $row = $db->insert('customer_message', [
            'website_id' => (int) $websiteId,
            'customer_id' => $customerId,
            'sender' => $sender === 'admin' ? 'admin' : 'customer',
            'admin_user_id' => $sender === 'admin' ? $adminUserId : null,
            'body' => $body,
        ]);

        return $this->thread($customerId, 1, (int) $row['message_id'] + 1)[0];
    }

    /**
     * Oldest first. $beforeId loads the ones before it (the screen's "anteriores").
     *
     * @return list<array{message_id: int, sender: string, body: string, created_at: string, read_at: ?string, admin_name: ?string}>
     */
    public function thread(int $customerId, int $limit = 100, ?int $beforeId = null): array
    {
        $bind = ['customer' => $customerId, 'limit' => max(1, min(100, $limit))];
        if ($beforeId !== null) {
            $bind['before'] = $beforeId;
        }
        $rows = Connection::get()->adapter()->fetchAll("SELECT m.message_id, m.sender, m.body, m.created_at, m.read_at,
                NULLIF(TRIM(CONCAT(u.firstname, ' ', u.lastname)), '') AS admin_name
            FROM customer_message m LEFT JOIN admin_user u ON u.user_id = m.admin_user_id
            WHERE m.customer_id = :customer" . ($beforeId !== null ? ' AND m.message_id < :before' : '') . '
            ORDER BY m.message_id DESC LIMIT :limit', $bind);

        return array_reverse(array_map(static fn (array $r) => ['message_id' => (int) $r['message_id']] + $r, $rows));
    }

    /** the reader opened the conversation: the other side's messages are read now */
    public function markRead(int $customerId, string $reader): int
    {
        return Connection::get()->adapter()->update('customer_message', ['read_at' => date('Y-m-d H:i:s')], [
            'customer_id = ?' => $customerId,
            'sender <> ?' => $reader,
            'read_at IS NULL',
        ]);
    }

    /** the store's messages the customer hasn't read (the app's icon) */
    public function unread(int $customerId): int
    {
        return (int) Connection::get()->adapter()->fetchOne("SELECT COUNT(*) FROM customer_message
            WHERE customer_id = :id AND sender = 'admin' AND read_at IS NULL", ['id' => $customerId]);
    }

    /** the customers' messages no admin has read (the menu's counter) */
    public static function unreadForAdmin(): int
    {
        return (int) Connection::get()->adapter()->fetchOne("SELECT COUNT(*) FROM customer_message WHERE sender = 'customer' AND read_at IS NULL");
    }

    /**
     * One row per customer with messages, the latest conversation first.
     *
     * @param array{unread_only?: bool, q?: string, website_id?: ?int} $filters q: part of the name or the e-mail
     * @return array{items: list<array>, total: int}
     */
    public function inbox(array $filters, int $page = 1, int $limit = self::PAGE_SIZE): array
    {
        $where = ['TRUE'];
        $bind = [];
        if (!empty($filters['unread_only'])) {
            $where[] = 't.unread > 0';
        }
        if (!empty($filters['website_id'])) {
            $where[] = 't.website_id = :website';
            $bind['website'] = (int) $filters['website_id'];
        }
        if (trim((string) ($filters['q'] ?? '')) !== '') {
            $where[] = "(c.email ILIKE :q OR CONCAT(c.firstname, ' ', c.lastname) ILIKE :q2)";
            $bind['q'] = $bind['q2'] = '%' . addcslashes(trim((string) $filters['q']), '%_\\') . '%';
        }
        $from = "FROM (SELECT customer_id, MAX(website_id) AS website_id, MAX(message_id) AS last_id,
                    COUNT(*) FILTER (WHERE sender = 'customer' AND read_at IS NULL) AS unread
                FROM customer_message GROUP BY customer_id) t
            JOIN customer_flat c ON c.customer_id = t.customer_id";
        $where = ' WHERE ' . implode(' AND ', $where);
        $db = Connection::get()->adapter();
        $total = (int) $db->fetchOne("SELECT COUNT(*) $from $where", $bind);
        $items = $db->fetchAll("SELECT t.customer_id, t.website_id, w.name AS website_name, c.firstname, c.lastname, c.email,
                m.body AS last_body, m.sender AS last_sender, m.created_at AS last_at, t.unread
            $from
            JOIN customer_message m ON m.message_id = t.last_id
            JOIN store_website w ON w.website_id = t.website_id
            $where
            ORDER BY t.last_id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . (max(1, $page) - 1) * max(1, $limit), $bind);

        return ['items' => $items, 'total' => $total];
    }
}
