<?php

declare(strict_types=1);

namespace Iter\Lgpd\View;

use Iter\Admin\View\GridColumns;
use Iter\Admin\View\Pager;
use Iter\Admin\View\Sort;
use Iter\Core\View\ViewComponentInterface;
use Iter\Lgpd\Model\RequestService;
use Iter\Store\Model\Website;

/** LGPD → Solicitações: every request, the open and late ones easy to find */
final class RequestList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'lgpd/requests';
    }

    public function getData(): array
    {
        $filters = [];
        foreach (['status', 'type', 'q', 'website_id', 'late'] as $key) {
            $filters[$key] = trim((string) ($_GET[$key] ?? ''));
        }
        $service = new RequestService();
        [$sort, $dir] = Sort::read(array_keys(RequestService::SORTS), 'id', 'desc');
        [$result, $pager] = Pager::search(static fn (int $page, int $limit) => $service->search($filters, $page, $limit, $sort, $dir));

        return [
            'requests' => $result['items'],
            'filters' => $filters,
            'websites' => array_column((new Website())->getList(), 'name', 'website_id'),
            'sort' => $sort,
            'dir' => $dir,
            'pager' => $pager,
            'gridColumns' => new GridColumns('lgpd/requests', $result['items'], ['id', 'email', 'type', 'origin', 'date', 'due', 'status']),
        ];
    }
}
