<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/** V1: per day, week or month, paid orders with the margin; waiting and canceled apart. Days without orders show up as zero. */
final class SalesByPeriod extends Report
{
    private const UNITS = ['day' => 'dia', 'week' => 'semana', 'month' => 'mês'];

    public function filters(): array
    {
        return [
            'group_by' => ['label' => 'Agrupar por', 'options' => ['day' => 'Dia', 'week' => 'Semana', 'month' => 'Mês']],
            'basis' => ['label' => 'Data', 'options' => ['created' => 'Data do pedido', 'paid' => 'Data do pagamento']],
        ];
    }

    public function columns(array $f): array
    {
        return [
            'period' => ['label' => ['day' => 'Dia', 'week' => 'Semana', 'month' => 'Mês'][$f['group_by']], 'type' => 'text', 'sort' => 'bucket'],
            'orders' => ['label' => 'Pedidos', 'type' => 'int', 'sum' => true],
            'items' => ['label' => 'Itens', 'type' => 'qty', 'sum' => true],
            'subtotal' => ['label' => 'Subtotal', 'type' => 'money', 'sum' => true],
            'discount' => ['label' => 'Descontos', 'type' => 'money', 'sum' => true],
            'shipping' => ['label' => 'Frete', 'type' => 'money', 'sum' => true],
            'total' => ['label' => 'Total', 'type' => 'money', 'sum' => true],
            'margin' => ['label' => 'Margem', 'type' => 'money', 'sum' => true, 'cost' => true],
            'margin_pct' => ['label' => 'Margem %', 'type' => 'pct', 'cost' => true],
            'ticket' => ['label' => 'Ticket médio', 'type' => 'money'],
            'pending' => ['label' => 'Aguardando', 'type' => 'int', 'sum' => true],
            'canceled' => ['label' => 'Cancelados', 'type' => 'int', 'sum' => true],
            'net' => ['label' => '', 'type' => 'money', 'sum' => true, 'hidden' => true],
            'net_cost' => ['label' => '', 'type' => 'money', 'sum' => true, 'hidden' => true],
            'cost' => ['label' => '', 'type' => 'money', 'sum' => true, 'hidden' => true],
        ];
    }

    public function sort(): array
    {
        return ['period', 'asc'];
    }

    public function lines(array $f): array
    {
        // the payment date is when the order first became "Pagamento confirmado"
        $date = $f['basis'] === 'paid'
            ? "(SELECT MIN(h.created_at) FROM sales_order_status_history h WHERE h.order_id = o.order_id AND h.status = 'processing')"
            : 'o.created_at';
        [$where, $bind] = self::orderWhere($f, 'o', $date, false);
        $paid = self::paid('o');
        $label = ['day' => "to_char(b.bucket, 'DD/MM/YYYY')", 'week' => "'Semana de ' || to_char(b.bucket, 'DD/MM/YYYY')", 'month' => "to_char(b.bucket, 'MM/YYYY')"][$f['group_by']];

        return ["WITH o AS (SELECT o.*, {$date} AS d FROM sales_order o WHERE {$where})
            SELECT b.bucket, {$label} AS period,
                COUNT(o.order_id) FILTER (WHERE {$paid}) AS orders,
                COALESCE(SUM(i.qty) FILTER (WHERE {$paid}), 0) AS items,
                COALESCE(SUM(o.subtotal) FILTER (WHERE {$paid}), 0) AS subtotal,
                COALESCE(SUM(o.discount_amount) FILTER (WHERE {$paid}), 0) AS discount,
                COALESCE(SUM(o.shipping_amount) FILTER (WHERE {$paid}), 0) AS shipping,
                COALESCE(SUM(o.grand_total) FILTER (WHERE {$paid}), 0) AS total,
                SUM(i.net) FILTER (WHERE {$paid}) AS net,
                SUM(i.net_cost) FILTER (WHERE {$paid}) AS net_cost,
                SUM(i.cost) FILTER (WHERE {$paid}) AS cost,
                SUM(i.net_cost) FILTER (WHERE {$paid}) - SUM(i.cost) FILTER (WHERE {$paid}) AS margin,
                (SUM(i.net_cost) FILTER (WHERE {$paid}) - SUM(i.cost) FILTER (WHERE {$paid})) / NULLIF(SUM(i.net_cost) FILTER (WHERE {$paid}), 0) AS margin_pct,
                SUM(o.grand_total) FILTER (WHERE {$paid}) / NULLIF(COUNT(o.order_id) FILTER (WHERE {$paid}), 0) AS ticket,
                COUNT(o.order_id) FILTER (WHERE o.status = 'pending_payment') AS pending,
                COUNT(o.order_id) FILTER (WHERE o.status = 'canceled') AS canceled
            FROM generate_series(date_trunc(:unit, CAST(:from AS timestamp)), CAST(:to AS timestamp), CAST('1 ' || :unit AS interval)) AS b(bucket)
            LEFT JOIN o ON o.d >= :from AND o.d < :to_next AND date_trunc(:unit, o.d) = b.bucket
            LEFT JOIN LATERAL (" . self::itemTotals('o') . ") i ON TRUE
            GROUP BY b.bucket", $bind + ['unit' => $f['group_by'], 'from' => $f['from'], 'to' => $f['to'], 'to_next' => $f['to_next']]];
    }

    public function totals(array $t, array $f): array
    {
        $t = self::margin($t);
        $t['ticket'] = (int) $t['orders'] > 0 ? (float) $t['total'] / (int) $t['orders'] : null;

        return $t;
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        // the same number of days just before, for ▲/▼
        $days = (int) round((strtotime($f['to_next']) - strtotime($f['from'])) / 86400);
        $before = $totalsOf(['from' => date('Y-m-d', strtotime($f['from'] . " -{$days} days")), 'to_next' => $f['from']] + $f);
        $change = (float) $before['total'] > 0 ? ((float) $t['total'] - (float) $before['total']) / (float) $before['total'] : null;
        $tiles = [
            ['label' => 'Faturamento', 'value' => Format::show('money', $t['total']), 'note' => $change === null ? 'sem vendas nos ' . $days . ' dias antes' : ($change >= 0 ? '▲ ' : '▼ ') . Format::show('pct', abs($change)) . ' vs. ' . $days . ' dias antes'],
            ['label' => 'Pedidos pagos', 'value' => Format::show('int', $t['orders']), 'note' => Format::show('qty', $t['items']) . ' itens'],
            ['label' => 'Ticket médio', 'value' => Format::show('money', $t['ticket']), 'note' => 'com frete'],
        ];
        if ($f['cost']) {
            $tiles[] = ['label' => 'Margem bruta', 'value' => Format::show('money', $t['margin']), 'note' => $t['margin_pct'] === null ? 'sem itens com custo' : Format::show('pct', $t['margin_pct']) . ' dos itens com custo', 'tone' => 'ok'];
        }
        $tiles[] = ['label' => 'Aguardando', 'value' => Format::show('int', $t['pending']), 'note' => 'fora do total', 'tone' => 'warn'];
        $tiles[] = ['label' => 'Cancelados', 'value' => Format::show('int', $t['canceled']), 'note' => 'fora do total', 'tone' => 'bad'];

        return $tiles;
    }

    public function chart(array $rows, array $f): ?array
    {
        usort($rows, static fn ($a, $b) => strcmp((string) $a['bucket'], (string) $b['bucket']));
        $short = ['day' => 'd/m', 'week' => 'd/m', 'month' => 'm/Y'][$f['group_by']];

        return ['title' => 'Faturamento por ' . self::UNITS[$f['group_by']], 'bars' => array_map(static fn ($r) => [
            'label' => date($short, strtotime((string) $r['bucket'])), 'value' => (float) $r['total'], 'part' => $f['cost'] && $r['margin'] !== null ? (float) $r['margin'] : null,
        ], $rows)];
    }

    public function note(array $t, array $f): string
    {
        return 'Os totais somam só os pedidos pagos (pagamento confirmado, enviado e concluído), o mesmo critério do Painel. Aguardando e cancelados aparecem à parte'
            . ($f['basis'] === 'paid' ? ' e, pela data do pagamento, só os que chegaram a ser pagos.' : '.')
            . ($f['cost'] ? ' Margem = itens (já com os descontos) − custo; o frete fica fora.' . self::withoutCost($t, 'net') : '');
    }
}
