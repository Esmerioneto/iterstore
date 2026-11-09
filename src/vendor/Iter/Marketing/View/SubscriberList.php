<?php

declare(strict_types=1);

namespace Iter\Marketing\View;

use Iter\Admin\View\GridColumns;
use Iter\Admin\View\Pager;
use Iter\Admin\View\Sort;
use Iter\Core\View\ViewComponentInterface;
use Iter\Marketing\Model\SubscriberService;
use Iter\Store\Model\Website;

/** Marketing → Inscritos */
final class SubscriberList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'marketing/subscribers';
    }

    /** @return array{status: string, website_id: string, q: string} from the query string (the list and its CSV) */
    public static function filters(): array
    {
        $filters = [];
        foreach (['status', 'website_id', 'q'] as $key) {
            $filters[$key] = trim((string) ($_GET[$key] ?? ''));
        }

        return $filters;
    }

    public function getData(): array
    {
        $filters = self::filters();
        $service = new SubscriberService();
        [$sort, $dir] = Sort::read(array_keys(SubscriberService::SORTS), 'date', 'desc');
        [$result, $pager] = Pager::search(static fn (int $page, int $limit) => $service->search($filters, $page, $limit, $sort, $dir));

        return [
            'subscribers' => $result['items'],
            'filters' => $filters,
            'websites' => array_column((new Website())->getList(), 'name', 'website_id'),
            'sort' => $sort,
            'dir' => $dir,
            'pager' => $pager,
            'total' => $result['total'],
            'export_url' => '/admin/marketingsubscriber/export?' . http_build_query(array_filter($filters, static fn ($v) => $v !== '')),
            'gridColumns' => new GridColumns('marketing/subscribers', $result['items'], ['date', 'email', 'website', 'status', 'confirmed']),
        ];
    }
}
