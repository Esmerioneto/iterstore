<?php

declare(strict_types=1);

namespace Iter\Marketing\Model\Rule;

/**
 * With at least P paid orders, the time since the last passed the customer's own average interval plus the
 * tolerance: average = (last − first) ÷ (orders − 1). 3 orders in 60 days → 30 days; with 20%, day 36.
 */
final class Overdue extends Rule
{
    public function code(): string
    {
        return 'overdue';
    }

    public function label(): string
    {
        return 'Fora do ritmo habitual';
    }

    public function candidates(int $websiteId): array
    {
        $rows = $this->db()->fetchAll('SELECT c.customer_id, c.email, c.firstname, s.last_id,
                ROUND(EXTRACT(EPOCH FROM (s.last_at - s.first_at) / (s.n - 1)) / 86400)::int AS every
            FROM customer_flat c ' . self::optedIn() . '
            JOIN LATERAL (SELECT COUNT(*) AS n, MIN(o.created_at) AS first_at, MAX(o.created_at) AS last_at,
                    (ARRAY_AGG(o.order_id ORDER BY o.created_at DESC, o.order_id DESC))[1] AS last_id
                FROM sales_order o WHERE o.customer_id = c.customer_id AND o.status IN ' . self::PAID . ') s ON s.n >= GREATEST(:orders, 2)
            WHERE c.website_id = :website AND c.is_active = 1 AND s.last_at > s.first_at
              AND NOW() - s.last_at > (s.last_at - s.first_at) / (s.n - 1) * (1 + :tolerance / 100.0)
            ORDER BY c.customer_id', ['website' => $websiteId, 'orders' => (int) $this->setting('min_orders', $websiteId), 'tolerance' => (int) $this->setting('tolerance', $websiteId)]);

        return array_map(static fn ($r) => self::customer($r, 'last_order:' . $r['last_id'], $websiteId, ['every' => (string) max(1, (int) $r['every'])]), $rows);
    }

    public function stillValid(array $suggestion): bool
    {
        return LastOrder::stillLast($suggestion);
    }
}
