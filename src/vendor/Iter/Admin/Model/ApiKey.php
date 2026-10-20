<?php

declare(strict_types=1);

namespace Iter\Admin\Model;

use Iter\Core\Db\Connection;

/**
 * The keys of the apps and sites that use the API (Sistema → Chaves da API). With Configurações → API →
 * "Exigir chave do aplicativo" on, a request without a valid X-Api-Key is refused, unless an admin is
 * logged in (panel session or admin token). The key is shown once; only its sha256 is kept.
 */
final class ApiKey
{
    /** the request's X-Api-Key is valid; marks it used (at most once a minute: one write, not one per request) */
    public static function valid(string $key): bool
    {
        if ($key === '') {
            return false;
        }
        $db = Connection::get()->adapter();
        $id = $db->fetchOne($db->select()->from('api_key', ['key_id'])->where('key_hash = ?', hash('sha256', $key))->where('is_active = 1'));
        if ($id === false) {
            return false;
        }
        $db->update('api_key', ['last_used_at' => date('Y-m-d H:i:s')], ['key_id = ?' => (int) $id, "(last_used_at IS NULL OR last_used_at < NOW() - INTERVAL '1 minute')"]);

        return true;
    }

    /** @return array{0: int, 1: string} the new key's id and the key itself (shown once) */
    public static function create(string $name, ?int $adminUserId): array
    {
        $key = 'itk_' . bin2hex(random_bytes(24));
        $row = Connection::get()->adapter()->insert('api_key', [
            'name' => $name,
            'key_hash' => hash('sha256', $key),
            'key_hint' => substr($key, 0, 8) . '…' . substr($key, -3),
            'created_by' => $adminUserId,
        ]);

        return [(int) $row['key_id'], $key];
    }

    public static function revoke(int $id): bool
    {
        return Connection::get()->adapter()->update('api_key', ['is_active' => 0, 'revoked_at' => date('Y-m-d H:i:s')], ['key_id = ?' => $id, 'is_active = 1']) > 0;
    }

    /** @return array<int, array> every key, active first */
    public static function all(): array
    {
        $db = Connection::get()->adapter();

        return $db->fetchAll('SELECT k.*, u.username AS created_by_name FROM api_key k LEFT JOIN admin_user u ON u.user_id = k.created_by ORDER BY k.is_active DESC, k.key_id DESC');
    }

    public static function get(int $id): ?array
    {
        return Connection::get()->adapter()->fetchRow('SELECT * FROM api_key WHERE key_id = ?', [$id]) ?: null;
    }
}
