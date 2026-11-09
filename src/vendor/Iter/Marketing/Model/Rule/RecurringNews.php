<?php

declare(strict_types=1);

namespace Iter\Marketing\Model\Rule;

use Iter\Inventory\Model\StockService;

/**
 * Customers with P paid orders in the last M days get, every N days, the products created in the last X days
 * (or, with none, the best sellers of those days). So do the newsletter's confirmed subscribers who aren't
 * customers, with their own template. Nothing to show = no suggestion.
 */
final class RecurringNews extends Rule
{
    public function code(): string
    {
        return 'recurring_news';
    }

    public function label(): string
    {
        return 'Novidades para recorrentes';
    }

    public function candidates(int $websiteId): array
    {
        $products = $this->products($websiteId);
        if ($products === '') {
            return [];
        }
        $rows = $this->db()->fetchAll('SELECT c.customer_id, c.email, c.firstname FROM customer_flat c ' . self::optedIn() . '
            WHERE c.website_id = :website AND c.is_active = 1
              AND (SELECT COUNT(*) FROM sales_order o WHERE o.customer_id = c.customer_id AND o.status IN ' . self::PAID . '
                   AND o.created_at > NOW() - make_interval(days => :period)) >= :orders
              AND NOT EXISTS (SELECT 1 FROM marketing_suggestion s WHERE s.rule = :rule AND lower(s.email) = lower(c.email)
                   AND s.created_at > NOW() - make_interval(days => :every))
            ORDER BY c.customer_id', ['website' => $websiteId, 'period' => (int) $this->setting('period_days', $websiteId),
            'orders' => max(1, (int) $this->setting('min_orders', $websiteId)), 'rule' => $this->code(), 'every' => (int) $this->setting('every_days', $websiteId)]);
        $today = date('Y-m-d');
        // NEWSLETTER-PLAN §1.4: and who confirmed the newsletter without being a customer of the website
        $subscribers = $this->db()->fetchCol("SELECT s.email FROM marketing_subscriber s
            WHERE s.website_id = :website AND s.status = 'confirmed'
              AND NOT EXISTS (SELECT 1 FROM customer_flat c WHERE c.website_id = s.website_id AND lower(c.email) = lower(s.email))
              AND NOT EXISTS (SELECT 1 FROM marketing_suggestion m WHERE m.rule = :rule AND lower(m.email) = lower(s.email)
                   AND m.created_at > NOW() - make_interval(days => :every))
            ORDER BY s.subscriber_id", ['website' => $websiteId, 'rule' => $this->code(), 'every' => (int) $this->setting('every_days', $websiteId)]);

        return [
            ...array_map(static fn ($r) => self::customer($r, "news:{$today}", $websiteId, ['products' => $products]), $rows),
            ...array_map(static fn ($email) => ['customer_id' => null, 'email' => (string) $email, 'reason_key' => "news:{$today}",
                'vars' => ['products' => $products, 'link' => self::shopUrl($websiteId)]], $subscribers),
        ];
    }

    /** a subscriber who isn't a customer gets the newsletter's text ("Obrigado por comprar" doesn't fit) */
    public function template(?array $suggestion = null): string
    {
        return $suggestion !== null && $suggestion['customer_id'] === null ? 'marketing_newsletter_news' : parent::template();
    }

    /** "<li><a href=…>Nome</a></li>…" of the website's new (or best-selling) products on sale and in stock */
    private function products(int $websiteId): string
    {
        $limit = max(1, (int) $this->setting('products', $websiteId));
        $days = (int) $this->setting('new_days', $websiteId);
        $base = 'FROM catalog_product p JOIN catalog_product_website w ON w.product_id = p.product_id AND w.website_id = :website
            WHERE p.is_active = 1 AND p.parent_id IS NULL';
        $bind = ['website' => $websiteId, 'days' => $days];
        $new = $this->db()->fetchAll("SELECT p.product_id, p.name, p.url_key {$base} AND p.created_at > NOW() - make_interval(days => :days)
            ORDER BY p.created_at DESC, p.product_id DESC", $bind);
        $best = $new ? [] : $this->db()->fetchAll("SELECT p.product_id, p.name, p.url_key {$base} AND p.product_id IN (
                SELECT COALESCE(v.parent_id, v.product_id) FROM sales_order_item i JOIN sales_order o ON o.order_id = i.order_id
                JOIN catalog_product v ON v.product_id = i.product_id
                WHERE o.website_id = :website AND o.status IN " . self::PAID . ' AND o.created_at > NOW() - make_interval(days => :days))
            ORDER BY (SELECT SUM(i.quantity) FROM sales_order_item i JOIN sales_order o ON o.order_id = i.order_id AND o.status IN ' . self::PAID . '
                JOIN catalog_product v ON v.product_id = i.product_id WHERE COALESCE(v.parent_id, v.product_id) = p.product_id
                AND o.created_at > NOW() - make_interval(days => :days)) DESC, p.product_id', $bind);
        $list = $new ?: $best;
        if ($list && \isModuleEnabled('iter_inventory')) {
            $salable = (new StockService())->getSalable(array_column($list, 'product_id'), $websiteId); // a parent: its variants' sum
            $list = array_filter($list, static fn ($p) => (float) ($salable[(int) $p['product_id']] ?? 0) > 0);
        }
        $shop = self::shopUrl($websiteId);

        // ponytail: name and link only; a photo and the price would need public image URLs and the promo price rules
        return implode('', array_map(static fn ($p) => '<li><a href="' . htmlspecialchars($shop . '/' . $p['url_key']) . '">' . htmlspecialchars((string) $p['name']) . '</a></li>',
            array_slice($list, 0, $limit)));
    }
}
