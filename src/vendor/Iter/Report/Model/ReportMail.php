<?php

declare(strict_types=1);

namespace Iter\Report\Model;

use Iter\Core\Config;
use Iter\Mail\Model\MailService;
use Iter\Report\View\ReportView;

/**
 * Configurações → Relatórios → Envio automático por e-mail (bin/report-mail.php): the period that just
 * closed (weekly: on Monday, the week before; monthly: on the 1st, the month before), one file per
 * report, without cost or margin.
 */
final class ReportMail
{
    /**
     * @return ?array{from: string, to: string, message_id: int} null when there is nothing to send today
     * @throws \RuntimeException no valid recipient
     */
    public function run(int $today): ?array
    {
        $weekly = Config::get('report/email/frequency') !== 'monthly';
        if (!Config::get('report/email/enabled') || ($weekly ? date('N', $today) !== '1' : date('j', $today) !== '1')) {
            return null;
        }
        $recipients = array_values(array_filter(preg_split('/\s+/', (string) Config::get('report/email/recipients')), static fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        if (!$recipients) {
            throw new \RuntimeException('Relatórios por e-mail: nenhum destinatário válido em Configurações → Relatórios.');
        }
        $from = date('Y-m-d', strtotime($weekly ? '-7 days' : 'first day of last month', $today));
        $to = date('Y-m-d', strtotime('-1 day', $today));
        $codes = Config::get('report/email/reports') === 'all'
            ? array_keys(array_filter(ReportService::all(), static fn (array $info) => in_array($info['group'], ['sales', 'operation'], true)))
            : ['v1', 'v2'];
        $format = (string) Config::get('report/email/format');
        $dir = sys_get_temp_dir() . '/iter-report-mail-' . bin2hex(random_bytes(4)) . '/';
        mkdir($dir);
        try {
            $files = [];
            $titles = [];
            $summary = null;
            foreach ($codes as $code) {
                $report = ReportService::get((string) $code);
                if ($report === null) {
                    continue;
                }
                // each filter at its default (a checkbox on by default has to be sent: ReportService::filters)
                $in = ['preset' => '', 'from' => $from, 'to' => $to];
                foreach ($report->filters() as $key => $filter) {
                    if (($filter['type'] ?? '') === 'checkbox' && $filter['default']) {
                        $in[$key] = '1';
                    }
                }
                [$f] = ReportService::filters($report, $in, false); // the attachments never carry the cost
                $export = ReportView::export($report, $f, 'envio automático em ' . date('d/m/Y', $today));
                $path = $dir . $export->filename($format, $f);
                try {
                    $export->write($format, $path);
                } catch (\RuntimeException) { // a PDF over the line limit goes as a spreadsheet
                    $path = $dir . $export->filename('xlsx', $f);
                    $export->write('xlsx', $path);
                }
                $files[] = $path;
                $titles[] = $report->info['title'];
                $summary ??= $code === 'v1' ? (new ReportService())->totals($report, $f) : null;
            }

            $id = (new MailService())->send('report_digest', $recipients, [
                'period' => 'de ' . date('d/m/Y', strtotime($from)) . ' a ' . date('d/m/Y', strtotime($to)),
                'reports' => implode('', array_map(static fn ($t) => '<li>' . htmlspecialchars($t) . '</li>', $titles)),
                'total' => Format::show('money', $summary['total'] ?? null),
                'orders' => Format::show('int', $summary['orders'] ?? null),
                'store_name' => (string) (Config::get('store/info/name') ?: 'ITer Store'),
            ], ['attachments' => $files]);
        } finally {
            array_map('unlink', glob($dir . '*') ?: []); // the queue keeps its own copy (MailService::keepAttachment)
            rmdir($dir);
        }

        return ['from' => $from, 'to' => $to, 'message_id' => $id];
    }
}
