<?php

declare(strict_types=1);

namespace Iter\Marketing\Model\Rule;

/** Signed up D days ago or more and no paid order; once per customer */
final class NeverBought extends Rule
{
    public function code(): string
    {
        return 'never_bought';
    }

    public function label(): string
    {
        return 'Cadastrou e nunca comprou';
    }

    public function candidates(int $websiteId): array
    {
        $rows = $this->db()->fetchAll('SELECT c.customer_id, c.email, c.firstname FROM customer_flat c ' . self::optedIn() . '
            WHERE c.website_id = :website AND c.is_active = 1 AND c.created_at < NOW() - make_interval(days => :days)
              AND NOT EXISTS (SELECT 1 FROM sales_order o WHERE o.customer_id = c.customer_id AND o.status IN ' . self::PAID . ')
            ORDER BY c.customer_id', ['website' => $websiteId, 'days' => (int) $this->setting('days', $websiteId)]);

        return array_map(static fn ($r) => self::customer($r, 'customer:' . $r['customer_id'], $websiteId), $rows);
    }

    public function stillValid(array $suggestion): bool
    {
        return !$this->db()->fetchOne('SELECT 1 FROM sales_order o WHERE o.customer_id = ? AND o.status IN ' . self::PAID, [(int) $suggestion['customer_id']]);
    }
}
