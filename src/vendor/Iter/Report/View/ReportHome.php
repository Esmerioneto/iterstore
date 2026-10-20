<?php

declare(strict_types=1);

namespace Iter\Report\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Report\Model\ReportService;

/** Relatórios: one card per group with the reports the admin may open; ?group= shows only that one */
class ReportHome implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'report/home';
    }

    public function getData(): array
    {
        $only = isset(ReportService::GROUPS[$_GET['group'] ?? '']) ? (string) $_GET['group'] : null;
        $groups = [];
        foreach (ReportService::all() as $code => $info) {
            $report = ReportService::get((string) $code);
            if ($report !== null && ReportService::allowed($report) && ($only === null || $info['group'] === $only)) {
                $groups[$info['group']][] = $info;
            }
        }

        return ['groups' => array_intersect_key(ReportService::GROUPS, $groups), 'reports' => $groups];
    }
}
