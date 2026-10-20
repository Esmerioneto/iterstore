<?php

declare(strict_types=1);

namespace Iter\Core;

use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;

/**
 * Counts attempts per key in one-minute windows (table api_rate_limit): the API as a whole and the
 * logins, carts, orders and freight quotes per IP. The per-user lock still applies.
 */
class RateLimit
{
    /** Counts one hit; false when $key already passed $perMinute in this minute (0 = no limit) */
    public static function hit(string $key, int $perMinute): bool
    {
        if ($perMinute <= 0) {
            return true;
        }
        $db = Connection::get()->adapter();
        $window = date('Y-m-d H:i:00');
        $db->delete('api_rate_limit', ['window_start < ?' => $window]);
        $db->insertOnConflict('api_rate_limit', ['rate_key' => $key, 'window_start' => $window, 'hits' => 1], ['rate_key', 'window_start'], ['hits' => new Expr('api_rate_limit.hits + 1')]);

        return (int) $db->fetchOne($db->select()->from('api_rate_limit', ['hits'])->where('rate_key = ?', $key)->where('window_start = ?', $window)) <= $perMinute;
    }

    /**
     * The client's IP. Behind a proxy listed in Configurações → "Proxies confiáveis", the last address of
     * X-Forwarded-For that isn't one of them (the first ones can be typed by the client); otherwise REMOTE_ADDR.
     */
    public static function ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        $proxies = self::proxies();
        if (!in_array($remote, $proxies, true)) {
            return $remote;
        }
        foreach (array_reverse(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')))) as $ip) {
            if ($ip !== '' && !in_array($ip, $proxies, true) && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return $remote;
    }

    /** the request came over HTTPS (to this server, or to a trusted proxy that says so in X-Forwarded-Proto) */
    public static function https(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), self::proxies(), true)
            && strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https';
    }

    /** @return string[] Configurações → Proxies confiáveis */
    private static function proxies(): array
    {
        return preg_split('/\s+/', trim((string) Config::get('web/secure/trusted_proxies')), -1, PREG_SPLIT_NO_EMPTY);
    }
}
