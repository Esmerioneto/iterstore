<?php

declare(strict_types=1);

namespace Iter\Marketing\View;

use Iter\Admin\View\GridColumns;
use Iter\Admin\View\Pager;
use Iter\Admin\View\Sort;
use Iter\Core\View\ViewComponentInterface;
use Iter\Marketing\Model\MarketingService;
use Iter\Store\Model\Website;

/** Marketing → Histórico: every suggestion, filtered, with the return per rule on top */
final class HistoryList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'marketing/history';
    }

    public function getData(): array
    {
        $filters = [];
        foreach (['status', 'rule', 'q', 'website_id', 'from', 'to'] as $key) {
            $filters[$key] = trim((string) ($_GET[$key] ?? ''));
        }
        $service = new MarketingService();
        [$sort, $dir] = Sort::read(array_keys(MarketingService::SORTS), 'date', 'desc');
        [$result, $pager] = Pager::search(static fn (int $page, int $limit) => $service->search($filters, $page, $limit, $sort, $dir));
        foreach ($result['items'] as &$row) {
            $row['reason'] = MarketingService::reasonLabel($row);
        }
        unset($row);

        return [
            'suggestions' => $result['items'],
            'filters' => $filters,
            'stats' => $service->stats($filters),
            'rules' => array_map(static fn ($r) => $r->label(), MarketingService::rules()),
            'websites' => array_column((new Website())->getList(), 'name', 'website_id'),
            'sort' => $sort,
            'dir' => $dir,
            'pager' => $pager,
            'total' => $result['total'],
            'gridColumns' => new GridColumns('marketing/history', $result['items'], ['date', 'email', 'rule', 'reason', 'status', 'sent']),
        ];
    }
}
