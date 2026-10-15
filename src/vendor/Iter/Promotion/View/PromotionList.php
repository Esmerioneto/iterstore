<?php

declare(strict_types=1);
namespace Iter\Promotion\View;
use Iter\Admin\View\Sort;
use Iter\Core\Db\Connection;
use Iter\Core\View\ViewComponentInterface;
final class PromotionList implements ViewComponentInterface
{
    public function getTemplate(): string { return 'promotion/list'; }
    public function getData(): array
    {
        $rows = Connection::get()->adapter()->fetchAll("SELECT p.*, COALESCE((SELECT string_agg(w.name, ', ' ORDER BY w.name) FROM promotion_website pw JOIN store_website w ON w.website_id=pw.website_id WHERE pw.promotion_id=p.promotion_id),'') AS websites,
            (SELECT COUNT(*) FROM promotion_coupon c WHERE c.promotion_id=p.promotion_id) AS coupon_count,
            (SELECT COUNT(*) FROM sales_order_discount d WHERE d.promotion_id=p.promotion_id) AS redemption_count
            FROM promotion p ORDER BY p.priority, p.promotion_id DESC");
        $active = $_GET['is_active'] ?? '';
        $rows = in_array($active, ['0', '1'], true) ? array_filter($rows, static fn ($p) => (string) (int) $p['is_active'] === $active) : $rows;
        [$rows, $pager, $sort, $dir, $q] = Sort::slice($rows, ['priority' => 'priority', 'name' => 'name', 'application' => 'application_type', 'discount' => 'discount_amount',
            'websites' => 'websites', 'starts' => 'starts_at', 'is_active' => 'is_active', 'coupons' => 'coupon_count', 'uses' => 'redemption_count'], 'priority', 'asc', ['name', 'websites']);
        return ['promotions' => $rows, 'pager' => $pager, 'sort' => $sort, 'dir' => $dir, 'q' => $q, 'is_active' => in_array($active, ['0', '1'], true) ? $active : ''];
    }
}
