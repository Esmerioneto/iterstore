<?php

declare(strict_types=1);

namespace Iter\Lgpd\Model;

use Iter\Core\Db\Connection;

/**
 * LGPD for Iter_Lgpd's own records (see etc/lgpd.json): the copy lists the policy versions accepted, the cookie choices made logged in and the requests.
 * Erase keeps them: they are the proof that the policy was shown and the request answered.
 */
final class PersonalData implements PersonalDataInterface
{
    public function export(?int $customerId, string $email): array
    {
        $db = Connection::get()->adapter();
        $accepts = $customerId === null ? [] : $db->fetchAll('SELECT p.version, a.accepted_at FROM lgpd_policy_accept a JOIN lgpd_policy p ON p.policy_id = a.policy_id
            WHERE a.customer_id = :c ORDER BY a.accept_id', ['c' => $customerId]);
        $cookies = $customerId === null ? [] : $db->fetchAll('SELECT categories, created_at FROM lgpd_cookie_consent WHERE customer_id = :c ORDER BY consent_id', ['c' => $customerId]);
        $requests = $db->fetchAll('SELECT request_id, type, status, created_at FROM lgpd_request WHERE customer_id = :c OR LOWER(email) = :e ORDER BY request_id',
            ['c' => $customerId ?? 0, 'e' => mb_strtolower($email)]);

        return array_filter([
            'Política de privacidade aceita' => array_map(static fn ($a) => 'Versão ' . $a['version'] . ' em ' . substr((string) $a['accepted_at'], 0, 16), $accepts),
            'Aviso de cookies' => array_map(static fn ($c) => implode(', ', array_map(static fn ($code) => CookieCategories::CATEGORIES[$code][0] ?? $code, explode(',', $c['categories'])))
                . ' em ' . substr((string) $c['created_at'], 0, 16), $cookies),
            'Solicitações' => array_map(static fn ($r) => RequestService::protocol((int) $r['request_id']) . ' — ' . (RequestService::TYPES[$r['type']] ?? $r['type'])
                . ' — ' . (RequestService::STATUS_LABELS[$r['status']] ?? $r['status']) . ' — ' . substr((string) $r['created_at'], 0, 16), $requests),
        ]);
    }

    /**
     * LGPD-PLAN §10: the policy accepts go consent_years after the account was erased; the cookie choices
     * consent_years after they were made (the notice's cookie lasts a year); the requests answered or refused
     * request_years after they were opened. Open requests stay.
     */
    public function purge(array $retention): int
    {
        $db = Connection::get()->adapter();
        $count = 0;
        if (($years = (int) ($retention['consent_years'] ?? 0)) > 0) {
            $cut = date('Y-m-d H:i:s', strtotime("-{$years} years"));
            $count += $db->delete('lgpd_policy_accept', ['customer_id IN (SELECT customer_id FROM customer_entity WHERE anonymized_at < ?)' => $cut]);
            $count += $db->delete('lgpd_cookie_consent', ['created_at < ?' => $cut]);
        }
        if (($years = (int) ($retention['request_years'] ?? 0)) > 0) {
            $count += $db->delete('lgpd_request', ["status <> 'open'", 'created_at < ?' => date('Y-m-d H:i:s', strtotime("-{$years} years"))]);
        }

        return $count;
    }

    public function blockers(int $customerId): array
    {
        return [];
    }

    public function erase(int $customerId, string $email): void
    {
    }
}
