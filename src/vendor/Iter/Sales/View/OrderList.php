<?php

declare(strict_types=1);

namespace Iter\Sales\View;

use Iter\Admin\View\Pager;
use Iter\Admin\View\Sort;
use Iter\Core\View\ViewComponentInterface;
use Iter\Sales\Model\OrderService;
use Iter\Store\Model\Website;

class OrderList implements ViewComponentInterface
{
    public const PAGE_SIZES = [10, 20, 50, 100];
    public const SORTS = ['created_at', 'number', 'customer', 'total', 'status', 'payment'];

    public function getTemplate(): string
    {
        return 'sales/order-list';
    }

    public function getData(): array
    {
        $filters = [];
        foreach (['status', 'website_id', 'customer_id', 'sales_channel', 'payment_method', 'cancel_reason', 'q', 'from', 'to'] as $key) {
            $filters[$key] = trim((string) ($_GET[$key] ?? ''));
        }
        foreach (['from', 'to'] as $key) { // a bad date would break the query
            $filters[$key] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key]) ? $filters[$key] : '';
        }
        $filters['website_id'] = ctype_digit($filters['website_id']) ? $filters['website_id'] : '';
        $filters['customer_id'] = ctype_digit($filters['customer_id']) ? $filters['customer_id'] : '';
        $filters['cancel_reason'] = isset(OrderService::CANCEL_REASONS[$filters['cancel_reason']]) ? $filters['cancel_reason'] : '';
        [$sort, $dir] = Sort::read(self::SORTS, 'created_at', 'desc');
        [$result, $pager] = Pager::search(static fn (int $page, int $limit) => (new OrderService())->search($filters, $page, $limit, $sort, $dir), self::PAGE_SIZES);
        ['page' => $page, 'limit' => $limit] = $pager;

        return [
            'orders' => $result['items'],
            'filters' => $filters,
            'page' => $page,
            'limit' => $limit,
            'sort' => $sort,
            'dir' => $dir,
            'total' => $result['total'],
            'amount' => $result['amount'],
            'total_pages' => $pager['total_pages'],
            'pager' => $pager,
            'websites' => array_column((new Website())->getList(), 'name', 'website_id'),
            'channels' => (new OrderService())->channels(),
            'payments' => (new OrderService())->paymentMethods(),
        ];
    }
}
