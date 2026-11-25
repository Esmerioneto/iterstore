<?php

declare(strict_types=1);

namespace Iter\Marketing\Model;

use Iter\Core\Db\Connection;
use Iter\Lgpd\Model\PersonalDataInterface;

/**
 * LGPD for marketing (Iter_Lgpd calls it, see etc/lgpd.json). The consent history stays: it proves what the person
 * accepted and when (the customer's rows point only to the id; the opt-in leaving is recorded by the trigger when
 * the registration is erased). The newsletter subscription is anonymized and closed with a consent row "lgpd";
 * suggestions not sent yet are deleted and the others lose e-mail and content. "Não quero receber" (by e-mail)
 * stays, so the address is never mailed again.
 */
final class PersonalData implements PersonalDataInterface
{
    public const ERASED_EMAIL = 'excluido@anonimo.invalid';

    public function export(?int $customerId, string $email): array
    {
        $db = Connection::get()->adapter();
        $subscribers = $db->fetchAll('SELECT subscriber_id, status, created_at, confirmed_at, unsubscribed_at FROM marketing_subscriber WHERE LOWER(email) = :e ORDER BY subscriber_id',
            ['e' => mb_strtolower($email)]);
        $consent = $db->select()->from('marketing_consent', ['created_at', 'optin', 'origin'])->order('consent_id');
        $ids = array_map('intval', array_column($subscribers, 'subscriber_id'));
        $where = array_filter([$customerId !== null ? 'customer_id = ' . $customerId : null, $ids ? 'subscriber_id IN (' . implode(',', $ids) . ')' : null]);
        $consents = $where ? $db->fetchAll($consent->where(implode(' OR ', $where))) : [];
        $sent = $db->fetchAll('SELECT rule, sent_at FROM marketing_suggestion WHERE status = \'sent\' AND (customer_id = :c OR LOWER(email) = :e) ORDER BY suggestion_id',
            ['c' => $customerId ?? 0, 'e' => mb_strtolower($email)]);

        return array_filter([
            'Consentimento para ofertas por e-mail' => array_map(static fn ($c) => substr((string) $c['created_at'], 0, 16) . ' — ' . ($c['optin'] ? 'aceitou' : 'retirou')
                . ' (' . $c['origin'] . ')', $consents),
            'Newsletter' => array_map(static fn ($s) => (SubscriberService::STATUS_LABELS[$s['status']] ?? $s['status']) . ' — inscrito em ' . substr((string) $s['created_at'], 0, 16)
                . ($s['confirmed_at'] ? ', confirmado em ' . substr((string) $s['confirmed_at'], 0, 16) : '')
                . ($s['unsubscribed_at'] ? ', saiu em ' . substr((string) $s['unsubscribed_at'], 0, 16) : ''), $subscribers),
            'E-mails de marketing enviados' => array_map(static fn ($s) => substr((string) $s['sent_at'], 0, 16) . ' — ' . $s['rule'], $sent),
        ]);
    }

    public function blockers(int $customerId): array
    {
        return [];
    }

    public function erase(int $customerId, string $email): void
    {
        $db = Connection::get()->adapter();
        $email = mb_strtolower($email);
        foreach ($db->fetchAll('SELECT subscriber_id, status FROM marketing_subscriber WHERE LOWER(email) = :e', ['e' => $email]) as $s) {
            $id = (int) $s['subscriber_id'];
            $db->update('marketing_subscriber', ['email' => "excluido-s{$id}@anonimo.invalid", 'status' => 'unsubscribed', 'token_hash' => null, 'ip' => null,
                'unsubscribed_at' => date('Y-m-d H:i:s')], ['subscriber_id = ?' => $id]);
            $s['status'] !== 'unsubscribed' && $db->insert('marketing_consent', ['subscriber_id' => $id, 'optin' => 0, 'origin' => 'lgpd']);
        }
        $mine = ['customer_id = ' . $customerId . ' OR LOWER(email) = ?' => $email]; // one "?" per condition (bindInto)
        $db->delete('marketing_suggestion', $mine + ['status IN (?)' => ['pending', 'approved']]);
        $db->update('marketing_suggestion', ['email' => self::ERASED_EMAIL, 'vars' => '{}'], $mine);
    }
}
