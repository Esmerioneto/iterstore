<?php

declare(strict_types=1);

namespace Iter\Report\Model;

use Iter\Admin\Auth;
use Iter\Catalog\Model\Product;
use Iter\Core\Db\Adapter;
use Iter\Core\Db\Connection;
use Iter\Log\Model\OperationLog;

/**
 * The reports (REPORT-PLAN): the list from the modules' etc/reports.json, the filters from the request,
 * and a report's lines, page and totals. The lines are the report's SQL wrapped in an outer SELECT, so the
 * sort, the page and the sums work on its column keys for every report alike.
 */
final class ReportService
{
    /** the longest period of one query */
    public const MAX_DAYS = 366;

    /** @var array<string, string> period presets, the first is the default */
    public const PRESETS = [
        'month' => 'Mês atual', 'today' => 'Hoje', '7d' => 'Últimos 7 dias', '30d' => 'Últimos 30 dias',
        'last_month' => 'Mês anterior', '90d' => 'Últimos 90 dias', 'year' => 'Este ano', '' => 'Personalizado',
    ];

    public const GROUPS = ['sales' => ['Vendas', 'O que vendeu, quanto e como'], 'operation' => ['Operação', 'O que precisa de atenção hoje'],
        'stock' => ['Estoque e catálogo', 'O que tem, o que falta e o que corrigir no cadastro'],
        'customers' => ['Clientes', 'Quem compra, quem chegou e quem volta'],
        'finance' => ['Financeiro', 'O que entrou, o Mercado Pago e a margem']];

    /** groups whose exports go to the operation log (personal data, LGPD) */
    public const LOGGED = ['customers', 'finance'];

    /** @return array<string, array> code => info, the reports of the enabled modules */
    public static function all(): array
    {
        return array_column(\getModuleJson('etc' . DIRECTORY_SEPARATOR . 'reports.json'), null, 'code');
    }

    public static function get(string $code): ?Report
    {
        $info = self::all()[$code] ?? null;

        return $info && class_exists($info['class']) ? new $info['class']($info) : null;
    }

    /** the admin may open it (and the cost columns follow Product::COST_RESOURCE); a report of costs only ("cost": true) needs that too */
    public static function allowed(Report $report): bool
    {
        return Auth::isAllowed($report->info['resource']) && (empty($report->info['cost']) || Auth::isAllowed(Product::COST_RESOURCE));
    }

    /**
     * The filters from the request (or any array): unknown values fall back to the defaults.
     *
     * @return array{0: array<string, mixed>, 1: string[]} [filters, errors to show]
     */
    public static function filters(Report $report, array $in, ?bool $cost = null): array
    {
        $errors = [];
        $f = ['cost' => $cost ?? Auth::isAllowed(Product::COST_RESOURCE)];
        $preset = (string) ($in['preset'] ?? $report->preset());
        $preset = array_key_exists($preset, self::PRESETS) ? $preset : 'month';
        [$from, $to] = self::preset($preset) ?? [self::date($in['from'] ?? null), self::date($in['to'] ?? null)];
        if ($from === null || $to === null || $from > $to) {
            $errors[] = 'Período inválido: informe as duas datas, a primeira antes da segunda.';
            [$preset, [$from, $to]] = ['month', self::preset('month')];
        } elseif ((strtotime($to) - strtotime($from)) / 86400 + 1 > self::MAX_DAYS) {
            $errors[] = 'O período tem no máximo ' . self::MAX_DAYS . ' dias. Mostrando os ' . self::MAX_DAYS . ' dias até ' . date('d/m/Y', strtotime($to)) . '.';
            $from = date('Y-m-d', strtotime($to . ' -' . (self::MAX_DAYS - 1) . ' days'));
            $preset = '';
        }
        $f += ['preset' => $preset, 'from' => $from, 'to' => $to, 'to_next' => date('Y-m-d', strtotime($to . ' +1 day'))];
        $f['website_id'] = (int) ($in['website_id'] ?? 0) ?: null;
        $f['sales_channel'] = trim((string) ($in['sales_channel'] ?? ''));
        $f['payment_method'] = trim((string) ($in['payment_method'] ?? ''));
        foreach ($report->filters() as $key => $filter) {
            $value = $in[$key] ?? null;
            $f[$key] = match ($filter['type'] ?? 'select') {
                'select' => array_key_exists((string) $value, $filter['options']) ? (string) $value : (string) array_key_first($filter['options']),
                'number' => is_numeric($value) && (int) $value >= 0 ? (int) $value : $filter['default'],
                // a sent form without the box = off; no form yet = the default
                'checkbox' => $value !== null ? (bool) $value : (isset($in['preset']) ? false : $filter['default']),
                default => trim((string) $value),
            };
        }

        return [$f, $errors];
    }

    /** @return array<string, array> the columns the admin sees */
    public static function columns(Report $report, array $f): array
    {
        return array_filter($report->columns($f), static fn (array $c) => empty($c['hidden']) && ($f['cost'] || empty($c['cost'])));
    }

    /**
     * One page of lines (or all of them: $limit null), sorted by a column key.
     *
     * @return array{rows: array, total: int}
     */
    public function rows(Report $report, array $f, string $sort, string $dir, int $page = 1, ?int $limit = null): array
    {
        $db = $this->db();
        [$sql, $bind] = $report->lines($f);
        $column = $report->columns($f)[$sort] ?? null;
        $by = $db->quoteIdentifier($column['sort'] ?? $sort);
        $order = ' ORDER BY ' . $by . ($dir === 'desc' ? ' DESC NULLS LAST' : ' ASC NULLS FIRST');
        $rows = $db->fetchAll('SELECT * FROM (' . $sql . ') r' . $order . ($limit ? ' LIMIT ' . $limit . ' OFFSET ' . (max(1, $page) - 1) * $limit : ''), $bind);

        return ['rows' => $rows, 'total' => $limit ? (int) $db->fetchOne('SELECT COUNT(*) FROM (' . $sql . ') r', $bind) : count($rows)];
    }

    /** the sums of the 'sum' columns, then the report's ratios */
    public function totals(Report $report, array $f): array
    {
        $db = $this->db();
        $sums = array_keys(array_filter($report->columns($f), static fn (array $c) => !empty($c['sum'])));
        [$sql, $bind] = $report->totalsLines($f) ?? $report->lines($f);
        $t = $sums ? $db->fetchRow('SELECT ' . implode(', ', array_map(static fn ($k) => 'SUM(' . $db->quoteIdentifier($k) . ') AS ' . $db->quoteIdentifier($k), $sums)) . ' FROM (' . $sql . ') r', $bind) : [];

        return $report->totals($t ?: [], $f);
    }

    /** a file of personal or financial data (LOGGED) goes to the operation log: who took it out, and which part */
    public static function logExport(Report $report, Export $export, string $format): void
    {
        if (in_array($report->info['group'], self::LOGGED, true)) {
            OperationLog::record('export', 'report', null, $report->info['title'], [], ['Formato' => Export::LABELS[$format], 'Filtros' => $export->description, 'Linhas' => (string) $export->count()]);
        }
    }

    /** the period's dates of a preset; null = "Personalizado" */
    public static function preset(string $preset, ?int $now = null): ?array
    {
        $now ??= time();
        $today = date('Y-m-d', $now);

        return match ($preset) {
            'today' => [$today, $today],
            '7d' => [date('Y-m-d', strtotime($today . ' -6 days')), $today],
            '30d' => [date('Y-m-d', strtotime($today . ' -29 days')), $today],
            '90d' => [date('Y-m-d', strtotime($today . ' -89 days')), $today],
            'month' => [date('Y-m-01', $now), $today],
            'last_month' => [date('Y-m-01', strtotime(date('Y-m-01', $now) . ' -1 month')), date('Y-m-t', strtotime(date('Y-m-01', $now) . ' -1 month'))],
            'year' => [date('Y-01-01', $now), $today],
            default => null,
        };
    }

    /** "Período: 01/10/2026 a 16/10/2026 · Website: Loja RJ · ..." for the files */
    public static function describe(Report $report, array $f, array $websites, array $payments): string
    {
        $parts = in_array('period', $report->common(), true) ? ['Período: ' . date('d/m/Y', strtotime($f['from'])) . ' a ' . date('d/m/Y', strtotime($f['to']))] : [];
        $parts[] = 'Website: ' . ($f['website_id'] ? ($websites[$f['website_id']] ?? $f['website_id']) : 'todos');
        if (in_array('sales_channel', $report->common(), true)) {
            $parts[] = 'Canal: ' . ($f['sales_channel'] ?: 'todos');
        }
        if (in_array('payment_method', $report->common(), true)) {
            $parts[] = 'Pagamento: ' . ($f['payment_method'] !== '' ? ($payments[$f['payment_method']] ?? $f['payment_method']) : 'todos');
        }
        foreach ($report->filters() as $key => $filter) {
            $value = $f[$key];
            if (($filter['type'] ?? 'select') === 'select') {
                $parts[] = $filter['label'] . ': ' . $filter['options'][$value];
            } elseif ($value !== '' && $value !== false) {
                $parts[] = $filter['label'] . ($value === true ? '' : ': ' . $value);
            }
        }

        return implode(' · ', $parts);
    }

    private static function date(mixed $value): ?string
    {
        $date = \DateTime::createFromFormat('!Y-m-d', (string) $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function db(): Adapter
    {
        $db = Connection::get()->adapter();
        // a report never holds the database for long (the whole request: it is a report page or a file)
        $db->fetchOne("SELECT set_config('statement_timeout', '30s', false)");

        return $db;
    }
}
