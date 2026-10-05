<?php

declare(strict_types=1);

namespace Iter\Sales\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Sales\Model\OrderService;
use Iter\Store\Model\Website;

class OrderList implements ViewComponentInterface
{
    public const PAGE_SIZES = [20, 50, 100];
    public const SORTS = ['created_at', 'number', 'customer', 'total', 'status'];

    public function getTemplate(): string
    {
        return 'sales/order-list';
    }

    public function getData(): array
    {
        $filters = [];
        foreach (['status', 'website_id', 'customer_id', 'q', 'from', 'to'] as $key) {
            $filters[$key] = trim((string) ($_GET[$key] ?? ''));
        }
        foreach (['from', 'to'] as $key) { // a bad date would break the query
            $filters[$key] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key]) ? $filters[$key] : '';
        }
        $filters['website_id'] = ctype_digit($filters['website_id']) ? $filters['website_id'] : '';
        $filters['customer_id'] = ctype_digit($filters['customer_id']) ? $filters['customer_id'] : '';
        $limit = in_array((int) ($_GET['limit'] ?? 0), self::PAGE_SIZES, true) ? (int) $_GET['limit'] : self::PAGE_SIZES[0];
        $sort = in_array($_GET['sort'] ?? '', self::SORTS, true) ? (string) $_GET['sort'] : 'created_at';
        $dir = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = (new OrderService())->search($filters, $page, $limit, $sort, $dir);

        return [
            'orders' => $result['items'],
            'filters' => $filters,
            'page' => $page,
            'limit' => $limit,
            'sort' => $sort,
            'dir' => $dir,
            'total' => $result['total'],
            'amount' => $result['amount'],
            'total_pages' => max(1, (int) ceil($result['total'] / $limit)),
            'websites' => array_column((new Website())->getList(), 'name', 'website_id'),
        ];
    }
}
