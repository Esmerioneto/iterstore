<?php

declare(strict_types=1);

namespace Iter\Marketing\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Core\View\Layout;
use Iter\Log\Model\OperationLog;
use Iter\Marketing\Model\SubscriberService;
use Iter\Marketing\View\SubscriberList;

/** Marketing → Inscritos: who left the e-mail in the store's newsletter (NEWSLETTER-PLAN §4) */
class Marketingsubscriber
{
    public const ADMIN_RESOURCE = 'Iter_Marketing::subscribers';

    public function Index(): void
    {
        Breadcrumb::set([['label' => 'Dashboard', 'url' => '/admin/index'], ['label' => 'Marketing'], ['label' => 'Inscritos']]);

        echo Layout::renderAdmin('admin_marketingsubscriber_index');
    }

    /** the list as filtered on the screen, every page, in a .csv (goes to the operation log: it's personal data) */
    public function Export(): void
    {
        $filters = SubscriberList::filters();
        $rows = (new SubscriberService())->search($filters, 1, 0)['items'];
        OperationLog::record('export', 'marketing', null, 'Inscritos da newsletter', [], array_filter([
            'Linhas' => (string) count($rows),
            'Situação' => SubscriberService::STATUS_LABELS[$filters['status']] ?? '',
            'Website' => $filters['website_id'],
            'E-mail' => $filters['q'],
        ], static fn ($v) => $v !== ''));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="inscritos_' . date('Y-m-d') . '.csv"');
        header('Cache-Control: private, no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['E-mail', 'Website', 'Situação', 'Inscrito em', 'Confirmado em', 'Descadastrado em', 'Cliente', 'IP'], ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, [
                self::cell($r['email']), self::cell($r['website']), SubscriberService::STATUS_LABELS[$r['status']] ?? $r['status'],
                substr((string) $r['created_at'], 0, 16), substr((string) $r['confirmed_at'], 0, 16), substr((string) $r['unsubscribed_at'], 0, 16),
                $r['customer_id'] ? 'Sim' : 'Não', (string) $r['ip'],
            ], ';', '"', '');
        }
        fclose($out);
        exit;
    }

    /** a typed e-mail starting with = + - @ would run as a formula in Excel */
    private static function cell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && str_contains('=+-@', $value[0]) ? "'" . $value : $value;
    }
}
