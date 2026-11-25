<?php

declare(strict_types=1);

namespace Iter\Lgpd\Model;

use Iter\Core\Db\Connection;
use Iter\Core\RateLimit;

/**
 * The privacy policy per website (LGPD-PLAN §7): one draft being written and the published versions, never changed
 * (the table's trigger refuses it), and the customers' acceptances. A new version asks the customers to accept again:
 * the app compares Customer.privacyVersion with privacy.version.
 */
final class PolicyService
{
    /** plain text, like the store web's other texts; a long policy fits */
    public const MAX_LENGTH = 100000;

    /** the published version in force, null before the first @return array{policy_id: int, version: int, content: string, published_at: string}|null */
    public function current(int $websiteId): ?array
    {
        return Connection::get()->adapter()->fetchRow('SELECT policy_id, version, content, published_at FROM lgpd_policy
            WHERE website_id = :w AND version IS NOT NULL ORDER BY version DESC LIMIT 1', ['w' => $websiteId]) ?: null;
    }

    /** what the editor shows: the draft, or else the version in force to start from, or else an empty text */
    public function draft(int $websiteId): array
    {
        $draft = Connection::get()->adapter()->fetchRow('SELECT content, updated_at FROM lgpd_policy WHERE website_id = :w AND version IS NULL', ['w' => $websiteId]);
        if ($draft) {
            return ['content' => (string) $draft['content'], 'updated_at' => $draft['updated_at'], 'is_draft' => true];
        }

        return ['content' => (string) ($this->current($websiteId)['content'] ?? ''), 'updated_at' => null, 'is_draft' => false];
    }

    public function saveDraft(int $websiteId, string $content, ?int $adminUserId): void
    {
        $content = self::clean($content);
        $db = Connection::get()->adapter();
        $row = ['content' => $content, 'admin_user_id' => $adminUserId, 'updated_at' => date('Y-m-d H:i:s')];
        // the partial unique index (one draft per website) refuses a second one saved at the same instant
        $db->update('lgpd_policy', $row, ['website_id = ?' => $websiteId, 'version IS NULL']) || $db->insert('lgpd_policy', $row + ['website_id' => $websiteId]);
    }

    /**
     * Publishes $content as the next version and drops the draft. Returns the new version.
     *
     * @throws LgpdException POLICY_EMPTY, POLICY_UNCHANGED (same text as the version in force)
     */
    public function publish(int $websiteId, string $content, ?int $adminUserId): int
    {
        $content = self::clean($content);
        if (trim($content) === '') {
            throw new LgpdException('POLICY_EMPTY', 'Escreva a política antes de publicar.');
        }
        $db = Connection::get()->adapter();

        return $db->transaction(function () use ($db, $websiteId, $content, $adminUserId): int {
            // one publisher at a time per website: the next number is read under the lock
            $db->fetchOne('SELECT pg_advisory_xact_lock(hashtext(\'lgpd_policy\'), :w)', ['w' => $websiteId]);
            $current = $this->current($websiteId);
            if ($current !== null && $current['content'] === $content) {
                throw new LgpdException('POLICY_UNCHANGED', 'O texto é igual ao da versão ' . $current['version'] . ', já publicada. Nada para publicar.');
            }
            $version = (int) ($current['version'] ?? 0) + 1;
            $db->delete('lgpd_policy', ['website_id = ?' => $websiteId, 'version IS NULL']);
            $db->insert('lgpd_policy', ['website_id' => $websiteId, 'version' => $version, 'content' => $content, 'admin_user_id' => $adminUserId,
                'published_at' => date('Y-m-d H:i:s')]);

            return $version;
        });
    }

    /** @return list<array<string, mixed>> the published versions, newest first, with how many accepted each */
    public function versions(int $websiteId): array
    {
        return Connection::get()->adapter()->fetchAll("SELECT p.policy_id, p.version, p.published_at, NULLIF(TRIM(CONCAT_WS(' ', u.firstname, u.lastname)), '') AS admin_name,
                (SELECT COUNT(*) FROM lgpd_policy_accept a WHERE a.policy_id = p.policy_id) AS accepts
            FROM lgpd_policy p LEFT JOIN admin_user u ON u.user_id = p.admin_user_id
            WHERE p.website_id = :w AND p.version IS NOT NULL ORDER BY p.version DESC", ['w' => $websiteId]);
    }

    /** a published version, for reading @throws LgpdException */
    public function get(int $policyId): array
    {
        return Connection::get()->adapter()->fetchRow('SELECT p.*, w.name AS website FROM lgpd_policy p JOIN store_website w ON w.website_id = p.website_id
            WHERE p.policy_id = :id AND p.version IS NOT NULL', ['id' => $policyId]) ?: throw new LgpdException('POLICY_NOT_FOUND', 'Versão não encontrada.');
    }

    /**
     * The customer accepts the version in force of their website. $version must be it: an app showing an old text
     * can't record the acceptance of the new one. Accepting again is a no-op.
     *
     * @throws LgpdException POLICY_NOT_PUBLISHED, POLICY_VERSION_OUTDATED
     */
    public function accept(int $customerId, int $websiteId, int $version, string $origin): void
    {
        $current = $this->current($websiteId) ?? throw new LgpdException('POLICY_NOT_PUBLISHED', 'A loja ainda não publicou a política de privacidade.');
        if ((int) $current['version'] !== $version) {
            throw new LgpdException('POLICY_VERSION_OUTDATED', 'A política mudou (versão ' . $current['version'] . '). Mostre o texto novo e peça o aceite de novo.');
        }
        Connection::get()->adapter()->insertOnConflict('lgpd_policy_accept',
            ['customer_id' => $customerId, 'policy_id' => (int) $current['policy_id'], 'origin' => $origin, 'ip' => self::truncatedIp(RateLimit::ip())], ['customer_id', 'policy_id'], []);
    }

    /** the latest version of their website the customer accepted; null when none */
    public function acceptedVersion(int $customerId): ?int
    {
        $version = Connection::get()->adapter()->fetchOne('SELECT MAX(p.version) FROM lgpd_policy_accept a JOIN lgpd_policy p ON p.policy_id = a.policy_id
            JOIN customer_entity c ON c.customer_id = a.customer_id AND c.website_id = p.website_id WHERE a.customer_id = :c', ['c' => $customerId]);

        return $version === null || $version === false ? null : (int) $version;
    }

    /** enough to tell where it came from, not who: IPv4 without the last block, IPv6 its first 3 groups */
    public static function truncatedIp(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (string) preg_replace('/\.\d+$/', '.0', $ip);
        }
        $bin = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? inet_pton($ip) : false;

        return $bin === false ? null : (string) inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10));
    }

    private static function clean(string $content): string
    {
        $content = str_replace("\r\n", "\n", mb_scrub($content, 'UTF-8'));
        if (mb_strlen($content) > self::MAX_LENGTH) {
            throw new LgpdException('POLICY_TOO_LONG', 'A política passa de ' . number_format(self::MAX_LENGTH, 0, ',', '.') . ' caracteres.');
        }

        return rtrim($content);
    }
}
