<?php

declare(strict_types=1);

namespace Iter\Report\View;

use Iter\Admin\View\Pager;
use Iter\Admin\View\Sort;
use Iter\Core\View\ViewComponentInterface;
use Iter\Report\Model\Export;
use Iter\Report\Model\Report;
use Iter\Report\Model\ReportService;
use Iter\Sales\Model\OrderService;
use Iter\Store\Model\Website;

/** One report on the screen (?code=): filters, indicators, chart, the sorted page of lines and the totals */
class ReportView implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'report/view';
    }

    public function getData(): array
    {
        $report = ReportService::get((string) ($_GET['code'] ?? '')); // the controller already checked it
        [$f, $errors] = ReportService::filters($report, $_GET);
        $columns = ReportService::columns($report, $f);
        [$sort, $dir] = self::sort($report, $columns);
        $service = new ReportService();
        $totals = $service->totals($report, $f);
        // the chart needs every line (V1: one per day, 366 at most); the others only fetch their page
        $all = $report->chart([], $f) !== null ? $service->rows($report, $f, $sort, $dir)['rows'] : null;
        [$result, $pager] = Pager::search(static function (int $page, int $limit) use ($service, $report, $f, $sort, $dir, $all): array {
            if ($all !== null) {
                return ['items' => array_slice($all, ($page - 1) * $limit, $limit), 'total' => count($all)];
            }
            $r = $service->rows($report, $f, $sort, $dir, $page, $limit);

            return ['items' => $r['rows'], 'total' => $r['total']];
        }, [50, 100, 200]);
        $presets = [];
        foreach (ReportService::PRESETS as $key => $label) {
            $presets[$key] = ['label' => $label, 'dates' => ReportService::preset($key)];
        }

        return [
            'report' => $report,
            'f' => $f,
            'errors' => $errors,
            'columns' => $columns,
            'rows' => $result['items'],
            'totals' => $totals,
            'tiles' => $report->tiles($totals, $f, static fn (array $other) => $service->totals($report, $other)),
            'chart' => $all !== null ? $report->chart($all, $f) : null,
            'note' => $report->note($totals, $f),
            'sort' => $sort,
            'dir' => $dir,
            'pager' => $pager,
            'presets' => $presets,
            'websites' => self::websites(),
            'channels' => (new OrderService())->channels(),
            'payments' => (new OrderService())->paymentMethods(),
        ];
    }

    /** the file of the report as on the screen: same filters and sort, every line */
    public static function export(Report $report, array $f, string $by): Export
    {
        $columns = ReportService::columns($report, $f);
        [$sort, $dir] = self::sort($report, $columns);
        $service = new ReportService();
        $totals = $service->totals($report, $f);
        $tiles = $report->tiles($totals, $f, static fn (array $other) => $service->totals($report, $other));

        return new Export($report, $columns, $service->rows($report, $f, $sort, $dir)['rows'], $totals, $tiles,
            ReportService::describe($report, $f, self::websites(), (new OrderService())->paymentMethods()), $report->note($totals, $f), $by);
    }

    /** a link column's URL with the line's values in place of {alias}; null when one is empty (a guest has no customer screen) */
    public static function link(string $template, array $row): ?string
    {
        preg_match_all('/\{(\w+)\}/', $template, $keys);
        foreach ($keys[1] as $key) {
            if ((string) ($row[$key] ?? '') === '') {
                return null;
            }
        }

        return (string) preg_replace_callback('/\{(\w+)\}/', static fn ($m) => rawurlencode((string) $row[$m[1]]), $template);
    }

    /** @return array{0: string, 1: string} ?sort= among the visible columns, else the report's default */
    private static function sort(Report $report, array $columns): array
    {
        [$default, $defaultDir] = $report->sort();

        return Sort::read(array_map('strval', array_keys($columns)), $default, $defaultDir);
    }

    private static function websites(): array
    {
        return array_column((new Website())->getList(), 'name', 'website_id');
    }
}
