<?php

declare(strict_types=1);

namespace Iter\Marketing\Model\Rule;

/** The last paid order is N days old or more. Shares last_order:<id> with Overdue: one of the two per order */
final class Lapsed extends Rule
{
    public function code(): string
    {
        return 'lapsed';
    }

    public function label(): string
    {
        return 'Sem comprar há N dias';
    }

    public function candidates(int $websiteId): array
    {
        $rows = $this->db()->fetchAll('SELECT c.customer_id, c.email, c.firstname, lo.order_id, EXTRACT(DAY FROM NOW() - lo.created_at)::int AS days
            FROM customer_flat c ' . self::optedIn() . '
            JOIN LATERAL (SELECT o.order_id, o.created_at FROM sales_order o WHERE o.customer_id = c.customer_id AND o.status IN ' . self::PAID . '
                ORDER BY o.created_at DESC, o.order_id DESC LIMIT 1) lo ON TRUE
            WHERE c.website_id = :website AND c.is_active = 1 AND lo.created_at < NOW() - make_interval(days => :days)
            ORDER BY c.customer_id', ['website' => $websiteId, 'days' => (int) $this->setting('days', $websiteId)]);

        return array_map(static fn ($r) => self::customer($r, 'last_order:' . $r['order_id'], $websiteId, ['days' => (string) $r['days']]), $rows);
    }

    public function stillValid(array $suggestion): bool
    {
        return LastOrder::stillLast($suggestion);
    }
}
