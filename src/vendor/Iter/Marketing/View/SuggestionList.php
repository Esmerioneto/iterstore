<?php

declare(strict_types=1);

namespace Iter\Marketing\View;

use Iter\Admin\View\GridColumns;
use Iter\Admin\View\Pager;
use Iter\Admin\View\Sort;
use Iter\Core\View\ViewComponentInterface;
use Iter\Marketing\Model\MarketingService;
use Iter\Store\Model\Website;

/** Marketing → Sugestões: the pending ones (Histórico has every status) */
final class SuggestionList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'marketing/list';
    }

    public function getData(): array
    {
        $filters = ['status' => 'pending'];
        foreach (['rule', 'q', 'website_id'] as $key) {
            $filters[$key] = trim((string) ($_GET[$key] ?? ''));
        }
        $service = new MarketingService();
        [$sort, $dir] = Sort::read(array_keys(MarketingService::SORTS), 'date', 'asc');
        [$result, $pager] = Pager::search(static fn (int $page, int $limit) => $service->search($filters, $page, $limit, $sort, $dir));
        foreach ($result['items'] as &$row) {
            $row['reason'] = MarketingService::reasonLabel($row);
        }
        unset($row);

        return [
            'suggestions' => $result['items'],
            'filters' => $filters,
            'rules' => array_map(static fn ($r) => $r->label(), MarketingService::rules()),
            'websites' => array_column((new Website())->getList(), 'name', 'website_id'),
            'sort' => $sort,
            'dir' => $dir,
            'pager' => $pager,
            'total' => $result['total'],
            'back' => (string) ($_SERVER['REQUEST_URI'] ?? '/admin/marketing/index'),
            'gridColumns' => new GridColumns('marketing/list', $result['items'], ['date', 'email', 'rule', 'reason']),
        ];
    }
}
