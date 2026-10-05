<?php

declare(strict_types=1);

namespace Iter\Sales\View;

use Iter\Admin\Auth;
use Iter\Core\View\ViewComponentInterface;
use Iter\Sales\Model\OrderService;

class DashboardSummary implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'sales/dashboard-summary';
    }

    public function getData(): array
    {
        return ['counts' => Auth::isAllowed('Iter_Sales::order') ? (new OrderService())->summary() : null];
    }
}
