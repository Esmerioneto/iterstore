<?php

declare(strict_types=1);

namespace Iter\Sales\View;

use Iter\Admin\Auth;
use Iter\Core\View\ViewComponentInterface;
use Iter\Sales\Model\DashboardService;
use Iter\Sales\Model\OrderService;
use Iter\Store\Model\Website;

/**
 * Vendas on the admin Dashboard: the sales dashboard (DASHBOARD-PLAN) for a profile with Iter_Sales::dashboard,
 * which takes the whole screen (theme.css hides the other cards); otherwise the order counts card.
 */
class DashboardSummary implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return Auth::isAllowed('Iter_Sales::dashboard') ? 'sales/sales-dashboard' : 'sales/dashboard-summary';
    }

    public function getData(): array
    {
        if (Auth::isAllowed('Iter_Sales::dashboard')) {
            // sales-dashboard.js asks /admin/salesdashboard/data for the numbers
            return ['websites' => array_column((new Website())->getList(), 'name', 'website_id'), 'periods' => DashboardService::PERIODS];
        }

        return ['counts' => Auth::isAllowed('Iter_Sales::order') ? (new OrderService())->summary() : null];
    }
}
