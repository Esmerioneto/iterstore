<?php

declare(strict_types=1);

namespace Iter\Lgpd\Model;

use Iter\Core\Db\Connection;

/**
 * LGPD for Iter_Lgpd's own records (see etc/lgpd.json): the copy lists the policy versions accepted and the requests.
 * Erase keeps them: they are the proof that the policy was shown and the request answered.
 */
final class PersonalData implements PersonalDataInterface
{
    public function export(?int $customerId, string $email): array
    {
        $db = Connection::get()->adapter();
        $accepts = $customerId === null ? [] : $db->fetchAll('SELECT p.version, a.accepted_at FROM lgpd_policy_accept a JOIN lgpd_policy p ON p.policy_id = a.policy_id
            WHERE a.customer_id = :c ORDER BY a.accept_id', ['c' => $customerId]);
        $requests = $db->fetchAll('SELECT request_id, type, status, created_at FROM lgpd_request WHERE customer_id = :c OR LOWER(email) = :e ORDER BY request_id',
            ['c' => $customerId ?? 0, 'e' => mb_strtolower($email)]);

        return array_filter([
            'Política de privacidade aceita' => array_map(static fn ($a) => 'Versão ' . $a['version'] . ' em ' . substr((string) $a['accepted_at'], 0, 16), $accepts),
            'Solicitações' => array_map(static fn ($r) => RequestService::protocol((int) $r['request_id']) . ' — ' . (RequestService::TYPES[$r['type']] ?? $r['type'])
                . ' — ' . (RequestService::STATUS_LABELS[$r['status']] ?? $r['status']) . ' — ' . substr((string) $r['created_at'], 0, 16), $requests),
        ]);
    }

    public function blockers(int $customerId): array
    {
        return [];
    }

    public function erase(int $customerId, string $email): void
    {
    }
}
