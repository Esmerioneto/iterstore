<?php

declare(strict_types=1);

namespace Iter\Store\View;

use Iter\Admin\Auth;
use Iter\Core\View\ViewComponentInterface;
use Iter\Store\Model\ResourceModel\Website;

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

        return ['counts' => (new Website())->getCounts()];
    }
}
