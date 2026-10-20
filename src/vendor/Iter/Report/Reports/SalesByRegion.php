<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/** V6: paid orders per state (UF) or city of the delivery address: orders, value, shipping and ticket */
final class SalesByRegion extends Report
{
    public const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    public function filters(): array
    {
        return [
            'by' => ['label' => 'Agrupar', 'options' => ['region' => 'Por UF', 'city' => 'Por cidade']],
            'region' => ['label' => 'UF', 'options' => ['' => 'Todas as UFs'] + array_combine(self::UFS, self::UFS)],
        ];
    }

    public function columns(array $f): array
    {
        return array_filter([
            'region' => ['label' => 'UF', 'type' => 'text'],
            'city' => $f['by'] === 'city' ? ['label' => 'Cidade', 'type' => 'text'] : null,
            'orders' => ['label' => 'Pedidos', 'type' => 'int', 'sum' => true],
            'customers' => ['label' => 'Clientes', 'type' => 'int'], // one customer in two cities counts in both: no sum
            'total' => ['label' => 'Valor', 'type' => 'money', 'sum' => true],
            'share' => ['label' => '% do valor', 'type' => 'pct', 'sum' => true],
            'ticket' => ['label' => 'Ticket médio', 'type' => 'money'],
            'shipping' => ['label' => 'Frete cobrado', 'type' => 'money', 'sum' => true],
            'avg_shipping' => ['label' => 'Frete médio', 'type' => 'money'],
        ]);
    }

    public function sort(): array
    {
        return ['total', 'desc'];
    }

    public function lines(array $f): array
    {
        [$where, $bind] = self::orderWhere($f);
        if ($f['region'] !== '') {
            $where .= ' AND UPPER(a.region) = :region';
            $bind['region'] = $f['region'];
        }
        $region = "COALESCE(NULLIF(UPPER(TRIM(a.region)), ''), 'Sem endereço')";
        $city = $f['by'] === 'city' ? "COALESCE(NULLIF(INITCAP(TRIM(a.city)), ''), '—')" : null;

        return ["SELECT {$region} AS region, " . ($city ?? 'NULL') . " AS city,
                COUNT(*) AS orders, COUNT(DISTINCT COALESCE('c' || o.customer_id, 'g' || LOWER(o.email))) AS customers,
                SUM(o.grand_total) AS total, SUM(o.grand_total) / NULLIF(SUM(SUM(o.grand_total)) OVER (), 0) AS share,
                SUM(o.grand_total) / COUNT(*) AS ticket, SUM(o.shipping_amount) AS shipping, AVG(o.shipping_amount) AS avg_shipping
            FROM sales_order o
            LEFT JOIN sales_order_address a ON a.order_id = o.order_id AND a.type = 'shipping'
            WHERE " . self::paid() . " AND {$where}
            GROUP BY {$region}" . ($city ? ", {$city}" : ''), $bind];
    }

    public function totals(array $t, array $f): array
    {
        $t['ticket'] = (int) $t['orders'] > 0 ? (float) $t['total'] / (int) $t['orders'] : null;
        $t['avg_shipping'] = (int) $t['orders'] > 0 ? (float) $t['shipping'] / (int) $t['orders'] : null;

        return $t;
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        return [
            ['label' => 'Pedidos pagos', 'value' => Format::show('int', $t['orders']), 'note' => Format::show('money', $t['total'])],
            ['label' => 'Frete cobrado', 'value' => Format::show('money', $t['shipping']), 'note' => 'média ' . Format::show('money', $t['avg_shipping']) . ' por pedido'],
            ['label' => 'Ticket médio', 'value' => Format::show('money', $t['ticket']), 'note' => 'com frete'],
        ];
    }

    public function note(array $t, array $f): string
    {
        return 'Pedidos pagos, pelo endereço de entrega gravado no pedido; os pedidos sem endereço de entrega aparecem como "Sem endereço".';
    }
}
