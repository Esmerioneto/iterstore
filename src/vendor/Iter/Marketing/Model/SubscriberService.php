<?php

declare(strict_types=1);

namespace Iter\Marketing\Model;

use Iter\Core\Config;
use Iter\Core\Db\Adapter;
use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Core\Log;
use Iter\Customer\Model\Customer;
use Iter\Eav\Model\AttributeException;
use Iter\Mail\Model\MailException;
use Iter\Mail\Model\MailService;

/**
 * NEWSLETTER-PLAN: the store's "Não perca nada". Double opt-in: the e-mail only receives after the link of the
 * confirmation e-mail is opened. Subscribing never tells whether the e-mail was there already (always true).
 * The confirmed ones who aren't customers get "Novidades" (Rule\RecurringNews); a customer's confirmation
 * checks the customer's own "Aceita receber ofertas por e-mail".
 */
final class SubscriberService
{
    public const STATUS_LABELS = ['pending' => 'Aguardando confirmação', 'confirmed' => 'Confirmado', 'unsubscribed' => 'Descadastrado'];

    /** the list's sortable columns: key => SQL (whitelist; the request only picks a key) */
    public const SORTS = ['date' => 's.subscriber_id', 'email' => 's.email', 'status' => 's.status', 'confirmed' => 's.confirmed_at'];

    /** a second confirmation e-mail to the same address waits this long */
    private const RESEND_SECONDS = 60;

    /**
     * @throws AttributeException INVALID_INPUT (the e-mail's format, an unknown website)
     */
    public function subscribe(string $email, int $websiteId, string $ip = ''): void
    {
        $email = mb_strtolower(trim($email));
        if (mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AttributeException('INVALID_INPUT', 'Informe um e-mail válido.');
        }
        if (!Config::websiteExists($websiteId)) {
            throw new AttributeException('INVALID_INPUT', 'Loja (websiteId) desconhecida.');
        }
        $db = $this->db();
        $token = $db->transaction(function () use ($db, $email, $websiteId, $ip): ?string {
            $db->insertOnConflict('marketing_subscriber', ['website_id' => $websiteId, 'email' => $email, 'ip' => mb_substr($ip, 0, 64)], []);
            $row = $db->fetchRow('SELECT subscriber_id, status, confirm_sent_at > NOW() - make_interval(secs => ?) AS recent
                FROM marketing_subscriber WHERE website_id = ? AND lower(email) = ? FOR UPDATE', [self::RESEND_SECONDS, $websiteId, $email]);
            if ($row['status'] === 'confirmed' || $row['recent']) {
                return null;
            }
            $token = bin2hex(random_bytes(32));
            $db->update('marketing_subscriber', [
                'status' => 'pending',
                'token_hash' => hash('sha256', $token),
                'token_expires_at' => new Expr('NOW() + make_interval(hours => ' . max(1, (int) Config::get('marketing/newsletter/confirm_hours', $websiteId)) . ')'),
                'confirm_sent_at' => new Expr('NOW()'),
                'ip' => mb_substr($ip, 0, 64),
            ], ['subscriber_id = ?' => (int) $row['subscriber_id']]);

            return $token;
        });
        if ($token === null) {
            return;
        }
        try {
            (new MailService())->send('marketing_newsletter_confirm', $email, [
                'link' => self::confirmUrl($token, $websiteId),
                'hours' => (string) max(1, (int) Config::get('marketing/newsletter/confirm_hours', $websiteId)),
            ], ['website_id' => $websiteId]);
        } catch (MailException $e) {
            Log::error('Newsletter: confirmação para ' . $email . ' não enfileirada: ' . $e->getMessage()); // the visitor still gets true
        }
    }

    /**
     * Single use; also makes the customer with the same e-mail (in the website) opt in.
     *
     * @throws AttributeException TOKEN_INVALID (unknown, used or expired)
     */
    public function confirm(string $token): void
    {
        $db = $this->db();
        // the error goes out after the transaction: thrown inside one, it would undo an outer transaction too
        $found = $db->transaction(static function () use ($db, $token): bool {
            $row = $db->fetchRow("SELECT subscriber_id, website_id, email FROM marketing_subscriber
                WHERE token_hash = ? AND status = 'pending' AND token_expires_at > NOW() FOR UPDATE", [hash('sha256', $token)]);
            if (!$row) {
                return false;
            }
            $id = (int) $row['subscriber_id'];
            $websiteId = (int) $row['website_id'];
            $db->update('marketing_subscriber', ['status' => 'confirmed', 'confirmed_at' => new Expr('NOW()'), 'unsubscribed_at' => null, 'token_hash' => null, 'token_expires_at' => null], ['subscriber_id = ?' => $id]);
            $db->insert('marketing_consent', ['subscriber_id' => $id, 'optin' => 1, 'origin' => 'newsletter']);
            // asked again, explicitly: an old "Não quero mais receber" of the visitor no longer holds
            $db->delete('marketing_unsubscribe', ['website_id = ?' => $websiteId, 'email = ?' => mb_strtolower((string) $row['email'])]);
            $customerId = (new Customer())->getResource()->findIdByEmail((string) $row['email'], $websiteId);
            if ($customerId !== null) {
                $before = (int) $db->fetchOne('SELECT COALESCE(MAX(consent_id), 0) FROM marketing_consent WHERE customer_id = ?', [$customerId]);
                $db->insertOnConflict('eav_value_boolean', ['attribute_id' => Unsubscribe::optinAttributeId(), 'entity_id' => $customerId, 'value' => 1], ['attribute_id', 'entity_id']);
                // the trigger recorded the change (if it was off); it came from here
                $db->update('marketing_consent', ['origin' => 'newsletter'], ['customer_id = ?' => $customerId, 'consent_id > ?' => $before]);
            }

            return true;
        });
        if (!$found) {
            throw new AttributeException('TOKEN_INVALID', 'Link de confirmação inválido ou vencido. Inscreva-se de novo para receber outro.');
        }
    }

    /** "Não quero mais receber" (Unsubscribe::apply): the subscriber of that e-mail stops, with the consent row */
    public static function unsubscribe(string $email, int $websiteId): void
    {
        $db = Connection::get()->adapter();
        $id = $db->fetchOne("SELECT subscriber_id FROM marketing_subscriber WHERE website_id = ? AND lower(email) = ? AND status <> 'unsubscribed'", [$websiteId, mb_strtolower(trim($email))]);
        if ($id === false) {
            return;
        }
        $db->update('marketing_subscriber', ['status' => 'unsubscribed', 'unsubscribed_at' => new Expr('NOW()'), 'token_hash' => null, 'token_expires_at' => null], ['subscriber_id = ?' => (int) $id]);
        $db->insert('marketing_consent', ['subscriber_id' => (int) $id, 'optin' => 0, 'origin' => 'newsletter']);
    }

    /** a confirmed subscriber may receive marketing (Unsubscribe::canReceive, for who isn't a customer) */
    public static function isConfirmed(string $email, int $websiteId): bool
    {
        return (bool) Connection::get()->adapter()->fetchOne("SELECT 1 FROM marketing_subscriber WHERE website_id = ? AND lower(email) = ? AND status = 'confirmed'", [$websiteId, mb_strtolower(trim($email))]);
    }

    /**
     * Marketing → Inscritos. Filters: website_id, status, q (part of the e-mail). $perPage 0 = every row (the CSV).
     *
     * @return array{items: array, total: int}
     */
    public function search(array $filters, int $page, int $perPage, string $sort = 'date', string $dir = 'desc'): array
    {
        $db = $this->db();
        $select = $db->select()->from(['s' => 'marketing_subscriber'], [new Expr('COUNT(*)')])
            ->joinLeft(['w' => 'store_website'], 'w.website_id = s.website_id', []);
        if (isset(self::STATUS_LABELS[$filters['status'] ?? ''])) {
            $select->where('s.status = ?', $filters['status']);
        }
        if ((int) ($filters['website_id'] ?? 0) > 0) {
            $select->where('s.website_id = ?', (int) $filters['website_id']);
        }
        if (($filters['q'] ?? '') !== '') {
            $select->where('lower(s.email) LIKE ?', '%' . addcslashes(mb_strtolower((string) $filters['q']), '%_\\') . '%');
        }
        $total = (int) $db->fetchOne($select);
        $select->reset(\Iter\Core\Db\Select::COLUMNS)
            ->columns(['s.subscriber_id', 's.website_id', 's.email', 's.status', 's.ip', 's.created_at', 's.confirmed_at', 's.unsubscribed_at', new Expr('w.name AS website'),
                // a customer of the website with this e-mail (the list links to it)
                new Expr('(SELECT c.customer_id FROM customer_flat c WHERE c.website_id = s.website_id AND lower(c.email) = lower(s.email) LIMIT 1) AS customer_id')])
            ->order((self::SORTS[$sort] ?? 's.subscriber_id') . ($dir === 'asc' ? ' ASC' : ' DESC'))->order('s.subscriber_id DESC');
        $perPage > 0 && $select->limitPage($page, $perPage);

        return ['items' => $db->fetchAll($select), 'total' => $total];
    }

    /** Configurações → Marketing → Newsletter → link de confirmação, with {token} ("/…" = on the store's address); empty = /newsletter/confirmar */
    public static function confirmUrl(string $token, int $websiteId): string
    {
        return Config::storefrontLink((string) Config::get('marketing/newsletter/confirm_url', $websiteId), $token, $websiteId, '/newsletter/confirmar?token={token}');
    }

    private function db(): Adapter
    {
        return Connection::get()->adapter();
    }
}
