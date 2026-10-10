<?php

declare(strict_types=1);

namespace Iter\Sales\Controllers;

use Iter\Sales\Model\DashboardService;

/** The sales dashboard on the admin Dashboard (DASHBOARD-PLAN): the numbers it asks for every minute */
class Salesdashboard
{
    public const ADMIN_RESOURCE = 'Iter_Sales::dashboard';

    /** JSON for website_id (empty = all) and period (7, 30 or 90 days) */
    public function Data(): void
    {
        $website = (string) ($_GET['website_id'] ?? '');
        $period = (int) ($_GET['period'] ?? 0);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode((new DashboardService())->data(ctype_digit($website) ? (int) $website : null,
            in_array($period, DashboardService::PERIODS, true) ? $period : DashboardService::PERIODS[1]), JSON_UNESCAPED_UNICODE);
    }
}
