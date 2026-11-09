<?php

declare(strict_types=1);

namespace Iter\Marketing\Model\Rule;

/** A cart with items, no order, untouched for X hours; an optional second reminder after Y hours */
final class AbandonedCart extends Rule
{
    /** ponytail: carts idle this long past the reminder are left alone (turning the rule on doesn't mail every old cart) */
    private const STALE_HOURS = 48;

    public function code(): string
    {
        return 'abandoned_cart';
    }

    public function label(): string
    {
        return 'Carrinho abandonado';
    }

    public function evenWithoutOptin(int $websiteId): bool
    {
        return (bool) \Iter\Core\Config::get('marketing/general/cart_without_optin', $websiteId);
    }

    public function candidates(int $websiteId): array
    {
        $candidates = [];
        foreach ([1 => (int) $this->setting('hours_first', $websiteId), 2 => (int) $this->setting('hours_second', $websiteId)] as $step => $hours) {
            if ($hours <= 0) {
                continue;
            }
            // the 2nd reminder only after the 1st went out
            $after = $step === 2 ? "AND EXISTS (SELECT 1 FROM marketing_suggestion s WHERE s.reason_key = 'quote:' || q.quote_id || ':1' AND s.status = 'sent')" : '';
            $guests = $this->setting('guests', $websiteId) ? '' : 'AND q.customer_id IS NOT NULL';
            $rows = $this->db()->fetchAll("SELECT q.quote_id, q.customer_id, COALESCE(c.email, q.email) AS email, COALESCE(c.firstname, q.firstname, '') AS firstname
                FROM sales_quote q
                LEFT JOIN customer_flat c ON c.customer_id = q.customer_id
                WHERE q.website_id = :website AND q.is_active = 1 AND q.admin_user_id IS NULL
                  AND q.updated_at < NOW() - make_interval(hours => :hours) AND q.updated_at > NOW() - make_interval(hours => :stale)
                  AND COALESCE(c.email, q.email, '') <> '' AND (c.customer_id IS NULL OR c.is_active = 1)
                  AND EXISTS (SELECT 1 FROM sales_quote_item i WHERE i.quote_id = q.quote_id) {$guests} {$after}
                ORDER BY q.quote_id", ['website' => $websiteId, 'hours' => $hours, 'stale' => $hours + self::STALE_HOURS]);
            foreach ($rows as $row) {
                $candidates[] = [
                    'customer_id' => $row['customer_id'] === null ? null : (int) $row['customer_id'],
                    'email' => (string) $row['email'],
                    'reason_key' => "quote:{$row['quote_id']}:{$step}",
                    'vars' => ['name' => trim((string) $row['firstname']), 'link' => self::shopUrl($websiteId), 'items' => $this->items((int) $row['quote_id'])],
                ];
            }
        }

        return $candidates;
    }

    public function stillValid(array $suggestion): bool
    {
        $quoteId = (int) explode(':', $suggestion['reason_key'])[1];

        return (bool) $this->db()->fetchOne('SELECT 1 FROM sales_quote q WHERE q.quote_id = ? AND q.is_active = 1
            AND EXISTS (SELECT 1 FROM sales_quote_item i WHERE i.quote_id = q.quote_id)', [$quoteId]);
    }

    /** "<li>Camiseta × 2</li>…", escaped here: the template shows it with {{{ items }}} */
    private function items(int $quoteId): string
    {
        $rows = $this->db()->fetchAll('SELECT p.name, i.quantity FROM sales_quote_item i JOIN catalog_product p ON p.product_id = i.product_id
            WHERE i.quote_id = ? ORDER BY i.item_id', [$quoteId]);

        return implode('', array_map(static fn ($r) => '<li>' . htmlspecialchars((string) $r['name']) . ' × '
            . str_replace('.', ',', rtrim(rtrim((string) $r['quantity'], '0'), '.')) . '</li>', $rows));
    }
}
