<?php

declare(strict_types=1);

namespace Iter\Report\GraphQl;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Iter\Admin\Auth;
use Iter\Log\Model\OperationLog;
use Iter\Report\Controllers\Report as ReportController;
use Iter\Report\Model\Report;
use Iter\Report\Model\ReportService;

/**
 * The reports through the admin API (REPORT-PLAN delivery 5): the same lines, totals and indicators as
 * the screen, with the same permissions (each report's own, and the cost columns only with Iter_Catalog::cost).
 * The values are the raw ones (decimals as "195.00", dates as "2001-03-10 10:00:00"), for the client to format.
 */
class ReportSchema
{
    public const MAX_PAGE_SIZE = 1000;

    /** @return array{query: array, mutation: array} */
    public static function fields(): array
    {
        $option = new ObjectType(['name' => 'ReportFilterOption', 'fields' => ['value' => Type::nonNull(Type::string()), 'label' => Type::nonNull(Type::string())]]);
        $filter = new ObjectType([
            'name' => 'ReportFilter',
            'fields' => [
                'key' => Type::nonNull(Type::string()),
                'label' => Type::nonNull(Type::string()),
                'type' => ['type' => Type::nonNull(Type::string()), 'description' => 'select, search, number ou checkbox.'],
                'options' => ['type' => Type::listOf(Type::nonNull($option)), 'description' => 'Só no select; a primeira é o padrão.'],
                'default' => ['type' => Type::string(), 'description' => 'O padrão de number e checkbox ("1" = marcado).'],
            ],
        ]);
        $info = new ObjectType([
            'name' => 'ReportInfo',
            'fields' => [
                'code' => Type::nonNull(Type::string()),
                'group' => ['type' => Type::nonNull(Type::string()), 'description' => 'sales, operation, stock, customers ou finance.'],
                'groupLabel' => ['type' => Type::nonNull(Type::string()), 'resolve' => static fn (Report $r) => ReportService::GROUPS[$r->info['group']][0] ?? $r->info['group']],
                'title' => Type::nonNull(Type::string()),
                'description' => Type::nonNull(Type::string()),
                'common' => ['type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))), 'description' => 'Os filtros comuns que ele usa: period (preset, from, to), website_id, sales_channel, payment_method.', 'resolve' => static fn (Report $r) => $r->common()],
                'defaultPreset' => ['type' => Type::nonNull(Type::string()), 'resolve' => static fn (Report $r) => $r->preset()],
                'filters' => ['type' => Type::nonNull(Type::listOf(Type::nonNull($filter))), 'description' => 'Os filtros próprios do relatório.', 'resolve' => static fn (Report $r) => self::filters($r)],
            ],
            'resolveField' => static fn (Report $r, array $args, $context, $field) => $r->info[$field->fieldName] ?? null,
        ]);
        $column = new ObjectType([
            'name' => 'ReportColumn',
            'fields' => [
                'key' => Type::nonNull(Type::string()),
                'label' => Type::nonNull(Type::string()),
                'type' => ['type' => Type::nonNull(Type::string()), 'description' => 'text, int, qty, money, pct (0.125 = 12,5%), date, datetime ou age (horas).'],
                'link' => ['type' => Type::string(), 'description' => 'O endereço no admin, com {coluna} trocado pelo valor da linha.'],
            ],
        ]);
        $tile = new ObjectType([
            'name' => 'ReportTile',
            'fields' => ['label' => Type::nonNull(Type::string()), 'value' => Type::nonNull(Type::string()), 'note' => Type::string(), 'tone' => ['type' => Type::string(), 'description' => 'ok, warn ou bad.']],
        ]);
        $cell = new ObjectType(['name' => 'ReportValue', 'fields' => ['key' => Type::nonNull(Type::string()), 'value' => Type::string()]]);
        $result = new ObjectType([
            'name' => 'ReportResult',
            'fields' => [
                'report' => Type::nonNull($info),
                'applied' => ['type' => Type::nonNull(Type::listOf(Type::nonNull($cell))), 'description' => 'Os filtros usados de fato (período, website e os do relatório), depois de trocar os inválidos pelo padrão.'],
                'errors' => ['type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))), 'description' => 'Avisos sobre os filtros (ex.: período acima de 366 dias).'],
                'columns' => Type::nonNull(Type::listOf(Type::nonNull($column))),
                'rows' => ['type' => Type::nonNull(Type::listOf(Type::nonNull(Type::listOf(Type::string())))), 'description' => 'Uma lista por linha, com os valores na ordem de columns.'],
                'total' => ['type' => Type::nonNull(Type::int()), 'description' => 'Linhas em todas as páginas.'],
                'totals' => ['type' => Type::nonNull(Type::listOf(Type::string())), 'description' => 'A linha de total, na ordem de columns (null onde não soma).'],
                'tiles' => ['type' => Type::nonNull(Type::listOf(Type::nonNull($tile))), 'description' => 'Os indicadores do topo da tela, já formatados.'],
                'note' => Type::nonNull(Type::string()),
            ],
        ]);

        return [
            'query' => [
                'reports' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($info))),
                    'description' => 'Os relatórios que o admin do token pode abrir, com os filtros de cada um.',
                    'resolve' => static function (): array {
                        self::authenticate();

                        $reports = array_values(array_filter(array_map([ReportService::class, 'get'], array_keys(ReportService::all())), static fn (?Report $r) => $r !== null && ReportService::allowed($r)));
                        $order = array_flip(array_keys(ReportService::GROUPS));
                        usort($reports, static fn (Report $a, Report $b) => ($order[$a->info['group']] ?? 99) <=> ($order[$b->info['group']] ?? 99)); // the screen's groups; usort keeps the order inside one

                        return $reports;
                    },
                ],
                'report' => [
                    'type' => Type::nonNull($result),
                    'description' => 'Um relatório, como na tela: as linhas de uma página, o total e os indicadores. Os relatórios de clientes e financeiros ficam no log de operações, como as exportações.',
                    'args' => [
                        'code' => ['type' => Type::nonNull(Type::string()), 'description' => 'O código do relatório (reports), ex.: "v1".'],
                        'filters' => ['type' => Type::listOf(Type::nonNull(new InputObjectType(['name' => 'ReportFilterInput', 'fields' => ['key' => Type::nonNull(Type::string()), 'value' => Type::nonNull(Type::string())]]))),
                            'description' => 'preset (month, today, 7d, 30d, last_month, 90d, year ou "" com from e to em AAAA-MM-DD), website_id, sales_channel, payment_method e os filtros do relatório (checkbox: "1" ou ""). O que faltar fica no padrão.'],
                        'sort' => ['type' => Type::string(), 'description' => 'A chave de uma coluna; sem ela (ou inválida), a ordem padrão do relatório.'],
                        'direction' => ['type' => new EnumType(['name' => 'ReportSortDirection', 'values' => ['ASC' => ['value' => 'asc'], 'DESC' => ['value' => 'desc']]])],
                        'page' => ['type' => Type::int(), 'defaultValue' => 1],
                        'pageSize' => ['type' => Type::int(), 'defaultValue' => 100, 'description' => 'Máximo ' . self::MAX_PAGE_SIZE . '.'],
                    ],
                    'resolve' => static fn ($root, array $args): array => self::run($args),
                ],
            ],
            'mutation' => [],
        ];
    }

    private static function run(array $args): array
    {
        self::authenticate();
        $report = ReportService::get($args['code']);
        if ($report === null || !ReportService::allowed($report)) {
            throw new Error('Relatório não encontrado.', extensions: ['code' => 'NOT_FOUND']);
        }
        $in = array_column($args['filters'] ?? [], 'value', 'key');
        foreach ($report->filters() as $key => $f) { // ReportService::filters reads a sent form's missing checkbox as off
            if (($f['type'] ?? '') === 'checkbox' && !isset($in[$key])) {
                $in[$key] = $f['default'] ? '1' : '';
            }
        }
        [$f, $errors] = ReportService::filters($report, $in);
        $columns = ReportService::columns($report, $f);
        [$sort, $dir] = $report->sort();
        $sort = isset($args['sort'], $columns[$args['sort']]) ? $args['sort'] : $sort;
        $dir = $args['direction'] ?? (isset($args['sort']) && $sort === $args['sort'] ? 'asc' : $dir);
        $size = max(1, min(self::MAX_PAGE_SIZE, (int) $args['pageSize']));
        $service = new ReportService();
        $page = $service->rows($report, $f, $sort, $dir, max(1, (int) $args['page']), $size);
        $totals = $service->totals($report, $f);
        if (in_array($report->info['group'], ReportService::LOGGED, true)) {
            OperationLog::record('export', 'report', null, $report->info['title'], [], ['Formato' => 'API', 'Filtros' => ReportService::describe($report, $f, [], []), 'Linhas' => (string) count($page['rows'])]);
        }
        $raw = static fn ($v) => $v === null ? null : (is_bool($v) ? ($v ? '1' : '0') : (string) $v);
        $applied = [];
        foreach (array_merge(in_array('period', $report->common(), true) ? ['preset', 'from', 'to'] : [], ['website_id'], array_intersect(['sales_channel', 'payment_method'], $report->common()), array_keys($report->filters())) as $key) {
            $applied[] = ['key' => $key, 'value' => $raw($f[$key])];
        }

        return [
            'report' => $report,
            'applied' => $applied,
            'errors' => $errors,
            'columns' => array_map(static fn (string $key, array $c) => ['key' => $key, 'label' => $c['label'], 'type' => $c['type'], 'link' => $c['link'] ?? null], array_keys($columns), $columns),
            'rows' => array_map(static fn (array $row) => array_map(static fn (string $key) => $raw($row[$key] ?? null), array_keys($columns)), $page['rows']),
            'total' => $page['total'],
            'totals' => array_map(static fn (string $key) => $raw($totals[$key] ?? null), array_keys($columns)),
            'tiles' => $report->tiles($totals, $f, static fn (array $other) => $service->totals($report, $other)),
            'note' => $report->note($totals, $f),
        ];
    }

    /** UNAUTHENTICATED without a token, FORBIDDEN without any report permission (as Schema::guard) */
    private static function authenticate(): void
    {
        if (!Auth::isAllowed(ReportController::ADMIN_RESOURCE)) {
            throw new Error('Acesso negado.', extensions: ['code' => Auth::user() === null ? 'UNAUTHENTICATED' : 'FORBIDDEN']);
        }
    }

    private static function filters(Report $report): array
    {
        $list = [];
        foreach ($report->filters() as $key => $f) {
            $type = $f['type'] ?? 'select';
            $list[] = [
                'key' => $key,
                'label' => $f['label'],
                'type' => $type,
                'options' => $type === 'select' ? array_map(static fn ($v, $l) => ['value' => (string) $v, 'label' => $l], array_keys($f['options']), $f['options']) : null,
                'default' => $type === 'select' ? null : ($type === 'checkbox' ? ($f['default'] ? '1' : '') : (isset($f['default']) ? (string) $f['default'] : null)),
            ];
        }

        return $list;
    }
}
