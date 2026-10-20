<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Core\Db\Connection;
use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/** K1: sign-ups per day, week or month: PF and PJ, and how many of them have bought (a paid order, any day up to now) */
final class NewCustomers extends Report
{
    public function common(): array
    {
        return ['period', 'website_id'];
    }

    public function filters(): array
    {
        return [
            'group_by' => ['label' => 'Agrupar por', 'options' => ['day' => 'Dia', 'week' => 'Semana', 'month' => 'Mês']],
            'group_id' => ['label' => 'Grupo', 'options' => ['' => 'Todos os grupos'] + array_map('strval', Connection::get()->adapter()->fetchPairs('SELECT customer_group_id, name FROM customer_group ORDER BY name'))],
        ];
    }

    public function columns(array $f): array
    {
        return [
            'period' => ['label' => ['day' => 'Dia', 'week' => 'Semana', 'month' => 'Mês'][$f['group_by']], 'type' => 'text', 'sort' => 'bucket'],
            'signups' => ['label' => 'Cadastros', 'type' => 'int', 'sum' => true],
            'pf' => ['label' => 'Pessoa física', 'type' => 'int', 'sum' => true],
            'pj' => ['label' => 'Pessoa jurídica', 'type' => 'int', 'sum' => true],
            'buyers' => ['label' => 'Já compraram', 'type' => 'int', 'sum' => true],
            'buyers_pct' => ['label' => '% que comprou', 'type' => 'pct'],
        ];
    }

    public function sort(): array
    {
        return ['period', 'asc'];
    }

    public function lines(array $f): array
    {
        $where = '';
        $bind = ['unit' => $f['group_by'], 'from' => $f['from'], 'to' => $f['to'], 'to_next' => $f['to_next']];
        if ($f['website_id']) {
            $where .= ' AND c.website_id = :website_id';
            $bind['website_id'] = $f['website_id'];
        }
        if ($f['group_id'] !== '') {
            $where .= ' AND c.group_id = :group_id';
            $bind['group_id'] = (int) $f['group_id'];
        }
        $label = ['day' => "to_char(b.bucket, 'DD/MM/YYYY')", 'week' => "'Semana de ' || to_char(b.bucket, 'DD/MM/YYYY')", 'month' => "to_char(b.bucket, 'MM/YYYY')"][$f['group_by']];

        return ["SELECT b.bucket, {$label} AS period, COUNT(c.customer_id) AS signups,
                COUNT(c.customer_id) FILTER (WHERE c.customer_type = 'pj') AS pj,
                COUNT(c.customer_id) FILTER (WHERE c.customer_type IS DISTINCT FROM 'pj') AS pf,
                COUNT(c.customer_id) FILTER (WHERE c.bought) AS buyers,
                COUNT(c.customer_id) FILTER (WHERE c.bought)::numeric / NULLIF(COUNT(c.customer_id), 0) AS buyers_pct
            FROM generate_series(date_trunc(:unit, CAST(:from AS timestamp)), CAST(:to AS timestamp), CAST('1 ' || :unit AS interval)) AS b(bucket)
            LEFT JOIN (SELECT c.customer_id, c.customer_type, c.created_at,
                    EXISTS (SELECT 1 FROM sales_order o WHERE o.customer_id = c.customer_id AND " . self::paid() . ") AS bought
                FROM customer_flat c WHERE c.created_at >= :from AND c.created_at < :to_next{$where}) c ON date_trunc(:unit, c.created_at) = b.bucket
            GROUP BY b.bucket", $bind];
    }

    public function totals(array $t, array $f): array
    {
        $t['buyers_pct'] = (int) $t['signups'] > 0 ? (int) $t['buyers'] / (int) $t['signups'] : null;

        return $t;
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        $days = (int) round((strtotime($f['to_next']) - strtotime($f['from'])) / 86400);
        $before = (int) $totalsOf(['from' => date('Y-m-d', strtotime($f['from'] . " -{$days} days")), 'to' => date('Y-m-d', strtotime($f['from'] . ' -1 day')), 'to_next' => $f['from']] + $f)['signups'];
        $change = $before > 0 ? ((int) $t['signups'] - $before) / $before : null;

        return [
            ['label' => 'Novos clientes', 'value' => Format::show('int', $t['signups']), 'note' => $change === null ? 'nenhum nos ' . $days . ' dias antes' : ($change >= 0 ? '▲ ' : '▼ ') . Format::show('pct', abs($change)) . ' vs. ' . $days . ' dias antes'],
            ['label' => 'Pessoa física / jurídica', 'value' => Format::show('int', $t['pf']) . ' / ' . Format::show('int', $t['pj'])],
            ['label' => 'Já compraram', 'value' => Format::show('int', $t['buyers']), 'note' => $t['buyers_pct'] === null ? '' : Format::show('pct', $t['buyers_pct']) . ' dos novos', 'tone' => 'ok'],
        ];
    }

    public function note(array $t, array $f): string
    {
        return 'Pela data do cadastro. "Já compraram" = tem pelo menos um pedido pago (pagamento confirmado, enviado ou concluído), de qualquer data até hoje. Compras sem cadastro (visitante) não entram aqui.';
    }
}
