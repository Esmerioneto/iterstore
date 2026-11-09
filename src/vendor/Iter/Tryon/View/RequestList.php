<?php

declare(strict_types=1);

namespace Iter\Tryon\View;

use Iter\Admin\View\GridColumns;
use Iter\Admin\View\Pager;
use Iter\Admin\View\Sort;
use Iter\Core\View\ViewComponentInterface;
use Iter\Store\Model\Website;
use Iter\Tryon\Model\TryonService;

/** Clientes → Provador virtual: the generations, filtered, with the month's numbers on top */
final class RequestList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'tryon/list';
    }

    public function getData(): array
    {
        $filters = [];
        foreach (['status', 'provider', 'q', 'website_id', 'from', 'to'] as $key) {
            $filters[$key] = trim((string) ($_GET[$key] ?? ''));
        }
        $service = new TryonService();
        [$sort, $dir] = Sort::read(array_keys(TryonService::SORTS), 'date', 'desc');
        [$result, $pager] = Pager::search(static fn (int $page, int $limit) => $service->search($filters, $page, $limit, $sort, $dir));

        return [
            'requests' => $result['items'],
            'filters' => $filters,
            'summary' => $service->summary(),
            'providers' => $service->providersUsed(),
            'websites' => array_column((new Website())->getList(), 'name', 'website_id'),
            'sort' => $sort,
            'dir' => $dir,
            'pager' => $pager,
            'total' => $result['total'],
            'gridColumns' => new GridColumns('tryon/list', $result['items'], ['date', 'customer', 'product', 'model', 'status', 'seconds', 'cost', 'hits']),
        ];
    }
}
