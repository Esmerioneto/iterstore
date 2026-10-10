<?php

declare(strict_types=1);

namespace Iter\Sales\Model;

use Iter\Core\Db\Adapter;
use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Core\Db\Select;
use Iter\Core\Log;

/**
 * The sales dashboard's numbers (DASHBOARD-PLAN §1, decisions 6 to 6c), nothing stored in the DB: a file
 * per website in var/cache/sales-dashboard keeps one bucket per day of the last DAYS days (orders,
 * sales, per status group, per hour, payment, channel and parent product). Each refresh, in one
 * snapshot of the DB:
 * - redoes the days with an order created or changed since the watermark (the highest updated_at seen);
 * - once an hour, compares each day's fingerprint (count, sum, highest updated_at, status mix) with the
 *   DB's, CHECK_SLICE days per refresh, and redoes and logs the days that differ (an order deleted, a
 *   change made straight in the DB).
 * Product names and categories are read when the numbers are shown, so renaming one needs no redo.
 */
class DashboardService
{
    /** days kept: the longest period + the one before it, for the comparison */
    public const DAYS = 180;
    public const PERIODS = [7, 30, 90];
    /** status => its group in the dashboard */
    public const GROUPS = ['processing' => 'paid', 'shipped' => 'paid', 'complete' => 'paid', 'pending_payment' => 'pending', 'canceled' => 'canceled'];
    private const CHECK_EVERY = 3600;
    private const CHECK_SLICE = 30;
    /** a transaction may commit a little after its updated_at (NOW() is its start): the watermark looks back this much */
    private const MARK_OVERLAP = '1 minute';
    /** status => the code summed in the fingerprint, one digit per status: any status change moves the sum */
    private const STATUS_CODES = ['pending_payment' => 1, 'processing' => 10, 'shipped' => 100, 'complete' => 1000, 'canceled' => 10000];

    private string $dir;

    public function __construct(?string $dir = null)
    {
        // ITER_DASHBOARD_DIR: another folder, used by the selftest so it doesn't touch the real cache
        $this->dir = $dir ?? (getenv('ITER_DASHBOARD_DIR') ?: dirname(SRC) . '/var/cache/sales-dashboard/');
    }

    /**
     * The dashboard for the website (null = all of them) over the last $period days, today included.
     * Refreshes the cache files first.
     */
    public function data(?int $websiteId, int $period, ?int $now = null): array
    {
        $now ??= time();
        $db = Connection::get()->adapter();
        $websites = $websiteId === null ? array_map('intval', $db->fetchCol($db->select()->from('store_website', ['website_id']))) : [$websiteId];
        $days = [];
        $checks = [];
        foreach ($websites as $id) {
            $cache = $this->refresh($id, $now);
            foreach ($cache['days'] as $date => $bucket) {
                unset($bucket['fp']);
                $days[$date] = self::add($days[$date] ?? [], $bucket);
            }
            $checks[] = $cache['checked'];
        }
        $window = self::window($now);
        $current = array_slice($window, -$period);
        $previous = array_slice($window, -2 * $period, $period);
        $sum = static fn (array $dates) => array_reduce($dates, static fn (array $carry, string $d) => self::add($carry, $days[$d] ?? []), []);
        $total = $sum($current);
        $pair = static fn (?array $p) => ['orders' => (int) ($p[0] ?? 0), 'revenue' => round((float) ($p[1] ?? 0), 2)];

        $heat = array_fill(0, 7, array_fill(0, 24, 0));
        foreach ($current as $date) {
            foreach ($days[$date]['hours'] ?? [] as $hour => $orders) {
                $heat[(int) date('w', strtotime($date))][$hour] += $orders;
            }
        }
        $before = $sum($previous);
        $today = $days[end($window)] ?? [];

        return [
            'updated_at' => date('H:i:s', $now),
            // the oldest finished check of the websites, and what all of them fixed
            'check' => $checks ? ['at' => date('H:i', min(array_column($checks, 'at'))), 'fixed' => array_sum(array_column($checks, 'fixed'))] : null,
            'period' => $period,
            'totals' => [
                'created' => $pair([$total['orders'] ?? 0, $total['revenue'] ?? 0]),
                'paid' => $pair($total['status']['paid'] ?? null),
                'pending' => $pair($total['status']['pending'] ?? null),
                'canceled' => $pair($total['status']['canceled'] ?? null),
                'previous' => $pair([$before['orders'] ?? 0, $before['revenue'] ?? 0]),
                'today' => $pair([$today['orders'] ?? 0, $today['revenue'] ?? 0]),
            ],
            'days' => array_map(static fn (string $d) => ['date' => $d] + $pair([$days[$d]['orders'] ?? 0, $days[$d]['revenue'] ?? 0])
                + array_map($pair, array_map(static fn ($g) => $days[$d]['status'][$g] ?? null, ['paid' => 'paid', 'pending' => 'pending', 'canceled' => 'canceled'])), $current),
            'heat' => $heat,
            'payment' => [
                'methods' => self::ranked($total['payment'] ?? [], 0, PHP_INT_MAX, static fn ($label, array $p) => ['label' => (string) $label] + $pair($p)),
                // the last 14 days: method => orders
                'days' => array_map(static fn (string $d) => ['date' => $d, 'orders' => array_map(static fn (array $p) => (int) $p[0], $days[$d]['payment'] ?? [])], array_slice($window, -14)),
            ],
            'channels' => self::ranked($total['channel'] ?? [], 1, PHP_INT_MAX, static fn ($name, array $p) => ['name' => (string) $name] + $pair($p)),
            ...$this->catalog($total['products'] ?? []),
        ];
    }

    /**
     * Brings the website's cache file up to date and returns it. Two admins never refresh at once: who
     * comes while another refreshes gets the previous version.
     *
     * @return array{mark: ?string, days: array<string, array>, check: ?array, checked: array{at: int, fixed: int}}
     */
    public function refresh(int $websiteId, ?int $now = null): array
    {
        $now ??= time();
        is_dir($this->dir) || mkdir($this->dir, 0775, true);
        $file = $this->dir . "website-{$websiteId}.json";
        $lock = fopen($file . '.lock', 'c');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                $cache = self::read($file);
                if ($cache !== null) {
                    return $cache;
                }
                flock($lock, LOCK_EX); // the first build: wait for it
            }
            $cache = self::read($file) ?? ['mark' => null, 'days' => [], 'check' => null, 'checked' => ['at' => $now, 'fixed' => 0]];
            $window = self::window($now);
            $cache['days'] = array_intersect_key($cache['days'], array_flip($window));
            if ($cache['check'] === null && $now - $cache['checked']['at'] >= self::CHECK_EVERY) {
                $cache['check'] = ['next' => 0, 'fixed' => 0];
            }
            $db = Connection::get()->adapter();
            $db->transaction(function (Adapter $db) use (&$cache, $websiteId, $window, $now): void {
                $db->fetchAll('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY'); // one snapshot: the mark matches the days redone
                $mark = $db->fetchOne($db->select()->from('sales_order', ['m' => new Expr('MAX(updated_at)')])->where('website_id = ?', $websiteId));
                $changed = $cache['mark'] === null ? $window : array_intersect($window, $db->fetchCol($db->select()
                    ->from('sales_order', ['d' => new Expr('DISTINCT created_at::date')])->where('website_id = ?', $websiteId)
                    ->where('updated_at > CAST(? AS timestamp) - INTERVAL \'' . self::MARK_OVERLAP . '\'', $cache['mark'])));
                $this->redo($db, $cache, $websiteId, $changed);
                $cache['mark'] = $mark ?: '-infinity'; // no order yet: the next one is above it

                if ($cache['check'] !== null) {
                    $slice = array_slice($window, $cache['check']['next'], self::CHECK_SLICE);
                    $fresh = self::fingerprints($db, $websiteId, $slice);
                    $wrong = array_values(array_filter($slice, static fn (string $d) => ($cache['days'][$d]['fp'] ?? null) !== ($fresh[$d] ?? null)));
                    foreach ($wrong as $date) {
                        Log::notify("Painel de vendas: website {$websiteId}, dia {$date} diferente do banco; apurado de novo",
                            ['cache' => $cache['days'][$date]['fp'] ?? 'sem pedidos', 'banco' => $fresh[$date] ?? 'sem pedidos']);
                    }
                    $this->redo($db, $cache, $websiteId, $wrong);
                    $cache['check']['fixed'] += count($wrong);
                    $cache['check']['next'] += self::CHECK_SLICE;
                    if ($cache['check']['next'] >= count($window)) {
                        $cache['checked'] = ['at' => $now, 'fixed' => $cache['check']['fixed']];
                        $cache['check'] = null;
                    }
                }
            });
            $tmp = $file . '.' . getmypid() . '.tmp';
            file_put_contents($tmp, json_encode($cache, JSON_UNESCAPED_UNICODE));
            rename($tmp, $file); // atomic: a reader gets the old file or the new one, never half of it

            return $cache;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** the DAYS dates up to the one of $now, oldest first */
    public static function window(int $now): array
    {
        return array_map(static fn (int $i) => date('Y-m-d', strtotime("-{$i} days", $now)), range(self::DAYS - 1, 0));
    }

    private static function read(string $file): ?array
    {
        $cache = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($cache) && array_key_exists('mark', $cache) ? $cache : null;
    }

    /** replaces the buckets of $dates with the DB's (a date without orders loses its bucket) */
    private function redo(Adapter $db, array &$cache, int $websiteId, array $dates): void
    {
        if (!$dates) {
            return;
        }
        $buckets = array_map(static fn (string $fp) => ['fp' => $fp, 'orders' => 0, 'revenue' => 0, 'status' => [], 'hours' => array_fill(0, 24, 0),
            'payment' => [], 'channel' => [], 'products' => []], self::fingerprints($db, $websiteId, $dates));
        $orders = self::byDay($db->select()->from('sales_order', ['d' => new Expr('created_at::date'), 'status', 'h' => new Expr('EXTRACT(HOUR FROM created_at)::int'),
            'p' => new Expr("COALESCE(payment_label, 'Sem pagamento')"), 'c' => 'sales_channel', 'n' => new Expr('COUNT(*)'), 'v' => new Expr('SUM(grand_total)')]), 'website_id', $websiteId, $dates)
            ->group(['d', 'status', 'h', 'p', 'c']);
        foreach ($db->fetchAll($orders) as $r) {
            $b = &$buckets[$r['d']];
            [$n, $v] = [(int) $r['n'], (float) $r['v']];
            $b['orders'] += $n;
            $b['revenue'] += $v;
            $b['hours'][(int) $r['h']] += $n;
            foreach (['status' => self::GROUPS[$r['status']] ?? 'pending', 'payment' => $r['p'], 'channel' => $r['c']] as $key => $name) {
                $b[$key][$name] = [($b[$key][$name][0] ?? 0) + $n, ($b[$key][$name][1] ?? 0) + $v];
            }
            unset($b);
        }
        // variants count in their parent; a deleted product under 0
        $items = self::byDay($db->select()->from(['i' => 'sales_order_item'], ['pid' => new Expr('COALESCE(p.parent_id, p.product_id, 0)'),
            'q' => new Expr('SUM(i.quantity)'), 'v' => new Expr('SUM(i.row_total)')])
            ->join(['o' => 'sales_order'], 'o.order_id = i.order_id', ['d' => new Expr('o.created_at::date')])
            ->joinLeft(['p' => 'catalog_product'], 'p.product_id = i.product_id', []), 'o.website_id', $websiteId, $dates, 'o.')->group(['d', 'pid']);
        foreach ($db->fetchAll($items) as $r) {
            $buckets[$r['d']]['products'][$r['pid']] = [(float) $r['q'], (float) $r['v']];
        }
        foreach ($dates as $date) {
            unset($cache['days'][$date]);
        }
        $cache['days'] = $buckets + $cache['days'];
    }

    /**
     * date => "count|sum|highest updated_at|status mix" of the website's orders created on $dates (days without
     * orders left out). Reads only the index sales_order_dashboard (index-only scan).
     */
    private static function fingerprints(Adapter $db, int $websiteId, array $dates): array
    {
        if (!$dates) {
            return [];
        }
        $codes = implode(' ', array_map(static fn ($s, $c) => "WHEN '{$s}' THEN {$c}", array_keys(self::STATUS_CODES), self::STATUS_CODES));

        return $db->fetchPairs(self::byDay($db->select()->from('sales_order', ['d' => new Expr('created_at::date'),
            'fp' => new Expr("COUNT(*) || '|' || SUM(grand_total) || '|' || MAX(updated_at) || '|' || SUM(CASE status {$codes} ELSE 0 END)")]),
            'website_id', $websiteId, $dates)->group('d'));
    }

    /** the website's orders created on $dates (the range first, so the index on created_at is used) */
    private static function byDay(Select $select, string $websiteColumn, int $websiteId, array $dates, string $alias = ''): Select
    {
        return $select->where("{$websiteColumn} = ?", $websiteId)
            ->where("{$alias}created_at >= ?", min($dates))->where("{$alias}created_at < CAST(? AS date) + 1", max($dates))
            ->where("CAST({$alias}created_at AS date) IN (?)", array_values($dates));
    }

    /** the top 10 parent products by quantity and the top 10 categories by sales, named now */
    private function catalog(array $products): array
    {
        $db = Connection::get()->adapter();
        $ids = array_filter(array_map('intval', array_keys($products)));
        $names = $ids ? $db->fetchPairs($db->select()->from('catalog_product', ['product_id', 'name'])->where('product_id IN (?)', $ids)) : [];
        $categories = [];
        foreach ($ids ? $db->fetchAll($db->select()->from(['cp' => 'catalog_category_product'], ['product_id', 'category_id'])
            ->join(['c' => 'catalog_category'], 'c.category_id = cp.category_id', ['name'])->where('cp.product_id IN (?)', $ids)) : [] as $r) {
            $categories[$r['category_id']] = ['name' => $r['name'], 'revenue' => ($categories[$r['category_id']]['revenue'] ?? 0) + $products[$r['product_id']][1]];
        }
        usort($categories, static fn (array $a, array $b) => $b['revenue'] <=> $a['revenue']);

        return [
            'products' => self::ranked($products, 0, 10, static fn ($id, array $p) => ['id' => (int) $id, 'name' => $names[$id] ?? 'Produto excluído',
                'quantity' => $p[0], 'revenue' => round($p[1], 2)]),
            'categories' => array_map(static fn (array $c) => ['name' => $c['name'], 'revenue' => round($c['revenue'], 2)], array_slice($categories, 0, 10)),
        ];
    }

    /** the first $limit of key => [n, v], biggest $by first, through $map(key, pair) */
    private static function ranked(array $rows, int $by, int $limit, callable $map): array
    {
        uasort($rows, static fn (array $a, array $b) => $b[$by] <=> $a[$by]);
        $rows = array_slice($rows, 0, $limit, true);

        return array_map($map, array_keys($rows), $rows);
    }

    /** $a + $b, number by number (buckets of two days or two websites) */
    private static function add(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            $a[$key] = is_array($value) ? self::add($a[$key] ?? [], $value) : ($a[$key] ?? 0) + $value;
        }

        return $a;
    }
}
