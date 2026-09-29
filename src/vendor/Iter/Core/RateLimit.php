<?php

declare(strict_types=1);

namespace Iter\Core;

use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;

/**
 * Counts attempts per key in one-minute windows (table api_rate_limit), so the
 * API login can't be hammered from one IP. The per-user lock still applies.
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

    /** The client's IP. ponytail: REMOTE_ADDR only; behind a proxy/CDN read its trusted header here */
    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    }
}
