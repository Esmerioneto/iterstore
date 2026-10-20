<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/**
 * O2: how long each step took, per week or month of the order, from the status history: order →
 * payment confirmed, payment → shipped, shipped → completed (average and longest).
 */
final class ServiceTimes extends Report
{
    private const STEPS = ['pay' => 'Pedido → pagamento', 'ship' => 'Pagamento → envio', 'done' => 'Envio → conclusão'];

    public function common(): array
    {
        return ['period', 'website_id', 'sales_channel', 'payment_method'];
    }

    public function preset(): string
    {
        return '90d';
    }

    public function filters(): array
    {
        return ['group_by' => ['label' => 'Agrupar por', 'options' => ['week' => 'Semana', 'month' => 'Mês']]];
    }

    public function columns(array $f): array
    {
        $columns = [
            'period' => ['label' => $f['group_by'] === 'week' ? 'Semana' : 'Mês', 'type' => 'text', 'sort' => 'bucket'],
            'orders' => ['label' => 'Pedidos pagos', 'type' => 'int', 'sum' => true],
        ];
        foreach (self::STEPS as $step => $label) {
            // 'sum': the totals line is one line over every order (totalsLines), so the sum is the overall value
            $columns["{$step}_avg"] = ['label' => $label . ' (média)', 'type' => 'age', 'sum' => true];
            $columns["{$step}_max"] = ['label' => '(maior)', 'type' => 'age', 'sum' => true];
        }

        return $columns;
    }

    public function sort(): array
    {
        return ['period', 'asc'];
    }

    public function lines(array $f): array
    {
        [$sql, $bind] = $this->times($f);
        $label = $f['group_by'] === 'week' ? "'Semana de ' || to_char(t.bucket, 'DD/MM/YYYY')" : "to_char(t.bucket, 'MM/YYYY')";

        return ["SELECT t.bucket, {$label} AS period, " . $this->aggregates() . " FROM ({$sql}) t GROUP BY t.bucket", $bind];
    }

    public function totalsLines(array $f): ?array
    {
        [$sql, $bind] = $this->times($f);

        return ['SELECT ' . $this->aggregates() . " FROM ({$sql}) t", $bind];
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        $tiles = [];
        foreach (self::STEPS as $step => $label) {
            $tiles[] = ['label' => $label, 'value' => Format::show('age', $t["{$step}_avg"]), 'note' => 'em média; o maior: ' . Format::show('age', $t["{$step}_max"])];
        }

        return $tiles;
    }

    public function note(array $t, array $f): string
    {
        return 'Pedidos do período (pela data do pedido) que chegaram a ser pagos, com as datas do histórico: a primeira vez em "Pagamento confirmado", em "Enviado" e em "Concluído". Cada etapa conta só os pedidos que já passaram por ela.';
    }

    /** per paid order of the period: its bucket and the hours of each step (null while the step hasn't happened) */
    private function times(array $f): array
    {
        [$where, $bind] = self::orderWhere($f);
        $at = static fn (string $status) => "(SELECT MIN(h.created_at) FROM sales_order_status_history h WHERE h.order_id = o.order_id AND h.status = '{$status}')";

        return ["SELECT date_trunc(:unit, o.created_at) AS bucket,
                EXTRACT(EPOCH FROM s.paid - o.created_at) / 3600 AS pay,
                EXTRACT(EPOCH FROM s.shipped - s.paid) / 3600 AS ship,
                EXTRACT(EPOCH FROM s.completed - s.shipped) / 3600 AS done
            FROM sales_order o
            CROSS JOIN LATERAL (SELECT {$at('processing')} AS paid, {$at('shipped')} AS shipped, {$at('complete')} AS completed) s
            WHERE s.paid IS NOT NULL AND {$where}", $bind + ['unit' => $f['group_by']]];
    }

    private function aggregates(): string
    {
        $sql = ['COUNT(*) AS orders'];
        foreach (array_keys(self::STEPS) as $step) {
            $sql[] = "AVG(t.{$step}) AS {$step}_avg, MAX(t.{$step}) AS {$step}_max";
        }

        return implode(', ', $sql);
    }
}
