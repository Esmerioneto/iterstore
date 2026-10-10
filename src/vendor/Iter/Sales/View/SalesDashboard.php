<?php

declare(strict_types=1);

namespace Iter\Sales\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Sales\Model\DashboardService;
use Iter\Store\Model\Website;

/** Vendas → Painel de vendas: the frame; sales-dashboard.js asks /admin/salesdashboard/data for the numbers */
class SalesDashboard implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'sales/sales-dashboard';
    }

    public function getData(): array
    {
        return ['websites' => array_column((new Website())->getList(), 'name', 'website_id'), 'periods' => DashboardService::PERIODS];
    }
}
