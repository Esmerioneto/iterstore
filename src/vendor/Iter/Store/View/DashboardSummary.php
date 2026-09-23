<?php

declare(strict_types=1);

namespace Iter\Store\View;

use Iter\Admin\Auth;
use Iter\Core\Db\Connection;
use Iter\Core\View\ViewComponentInterface;

class DashboardSummary implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'store/dashboard-summary';
    }

    public function getData(): array
    {
        if (!Auth::isAllowed('Iter_Store::store')) {
            return ['counts' => null];
        }

        $counts = Connection::get()->connect()->query(
            'SELECT (SELECT COUNT(*) FROM store_website) AS websites,
                    (SELECT COUNT(*) FROM store_group) AS groups,
                    (SELECT COUNT(*) FROM store) AS stores,
                    (SELECT COUNT(*) FROM store WHERE group_id IS NULL) AS ungrouped'
        )->fetch(\PDO::FETCH_ASSOC);

        return ['counts' => array_map('intval', $counts)];
    }
}
