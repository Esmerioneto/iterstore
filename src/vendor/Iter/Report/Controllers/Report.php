<?php

declare(strict_types=1);

namespace Iter\Report\Controllers;

use Iter\Admin\Auth;
use Iter\Admin\Breadcrumb;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Report\Model\Export;
use Iter\Report\Model\Report as ReportModel;
use Iter\Report\Model\ReportService;
use Iter\Report\View\ReportView;

/** Relatórios: the list of reports, one report on the screen, and the same report as a CSV, XLSX or PDF file */
class Report
{
    public const ADMIN_RESOURCE = ['Iter_Report::sales', 'Iter_Report::stock', 'Iter_Report::customers', 'Iter_Report::finance']; // each report checks its own (report())

    public function Index(): void
    {
        $group = ReportService::GROUPS[$_GET['group'] ?? ''][0] ?? null;
        Breadcrumb::set(array_values(array_filter([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Relatórios'] + ($group ? ['url' => '/admin/report/index'] : []),
            $group ? ['label' => $group] : null,
        ])));

        echo Layout::renderAdmin('admin_report_index');
    }

    public function View(): void
    {
        $report = self::report();
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Relatórios', 'url' => '/admin/report/index'],
            ['label' => ReportService::GROUPS[$report->info['group']][0] ?? '', 'url' => '/admin/report/index?group=' . $report->info['group']],
            ['label' => $report->info['title']],
        ]);

        echo Layout::renderAdmin('admin_report_view');
    }

    public function Export(): void
    {
        $report = self::report();
        $format = (string) ($_GET['format'] ?? '');
        if (!isset(Export::FORMATS[$format])) {
            $format = 'csv';
        }
        [$f] = ReportService::filters($report, $_GET);
        $user = Auth::user();
        $export = ReportView::export($report, $f, 'gerado por ' . (trim($user->getData('firstname') . ' ' . $user->getData('lastname')) ?: $user->getData('username')) . ' em ' . date('d/m/Y \à\s H:i'));
        $path = tempnam(sys_get_temp_dir(), 'report');
        try {
            $export->write($format, $path);
        } catch (\RuntimeException $e) {
            @unlink($path);
            Messages::addError($e->getMessage());
            header('Location: /admin/report/view?' . http_build_query(array_diff_key($_GET, ['format' => 1])));
            exit;
        }
        ReportService::logExport($report, $export, $format);
        header('Content-Type: ' . Export::FORMATS[$format]);
        header('Content-Disposition: attachment; filename="' . $export->filename($format, $f) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-store');
        readfile($path);
        unlink($path);
        exit;
    }

    /** the report of ?code=, when the admin may see it; otherwise back to the list */
    private static function report(): ReportModel
    {
        $report = ReportService::get((string) ($_GET['code'] ?? ''));
        if ($report === null || !ReportService::allowed($report)) {
            Messages::addError('Relatório não encontrado.');
            header('Location: /admin/report/index');
            exit;
        }

        return $report;
    }
}
