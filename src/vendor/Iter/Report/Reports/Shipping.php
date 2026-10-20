<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/** V9: paid orders per shipping method: shipping charged, average, the promised days, free shipping and pickup */
final class Shipping extends Report
{
    public function columns(array $f): array
    {
        return [
            'method' => ['label' => 'Forma de entrega', 'type' => 'text'],
            'orders' => ['label' => 'Pedidos', 'type' => 'int', 'sum' => true],
            'share' => ['label' => '% dos pedidos', 'type' => 'pct', 'sum' => true],
            'shipping' => ['label' => 'Frete cobrado', 'type' => 'money', 'sum' => true],
            'avg_shipping' => ['label' => 'Frete médio', 'type' => 'money'],
            'free' => ['label' => 'Frete grátis', 'type' => 'int', 'sum' => true],
            'days' => ['label' => 'Prazo médio (dias)', 'type' => 'qty'],
            'total' => ['label' => 'Valor dos pedidos', 'type' => 'money', 'sum' => true],
            'pickup' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'days_sum' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'days_orders' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
        ];
    }

    public function sort(): array
    {
        return ['orders', 'desc'];
    }

    public function lines(array $f): array
    {
        [$where, $bind] = self::orderWhere($f);
        // the pickup label carries the shop's address: one line per method, with the latest label
        return ["SELECT (ARRAY_AGG(COALESCE(o.shipping_label, o.shipping_method, 'Sem entrega') ORDER BY o.created_at DESC))[1] AS method,
                COUNT(*) AS orders, COUNT(*)::numeric / NULLIF(SUM(COUNT(*)) OVER (), 0) AS share,
                SUM(o.shipping_amount) AS shipping, AVG(o.shipping_amount) AS avg_shipping,
                COUNT(*) FILTER (WHERE o.shipping_amount = 0) AS free,
                ROUND(AVG(o.shipping_days), 1) AS days, SUM(o.grand_total) AS total,
                COUNT(*) FILTER (WHERE o.shipping_method = 'pickup') AS pickup,
                COALESCE(SUM(o.shipping_days), 0) AS days_sum, COUNT(o.shipping_days) AS days_orders
            FROM sales_order o
            WHERE " . self::paid() . " AND {$where}
            GROUP BY COALESCE(o.shipping_method, '')", $bind];
    }

    public function totals(array $t, array $f): array
    {
        $t['avg_shipping'] = (int) $t['orders'] > 0 ? (float) $t['shipping'] / (int) $t['orders'] : null;
        $t['days'] = (int) $t['days_orders'] > 0 ? round((int) $t['days_sum'] / (int) $t['days_orders'], 1) : null;

        return $t;
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        $orders = (int) $t['orders'];
        $pct = static fn ($n) => $orders ? Format::show('pct', (int) $n / $orders) . ' dos pedidos' : '';

        return [
            ['label' => 'Frete cobrado', 'value' => Format::show('money', $t['shipping']), 'note' => 'média ' . Format::show('money', $t['avg_shipping']) . ' por pedido'],
            ['label' => 'Frete grátis', 'value' => Format::show('int', $t['free']), 'note' => $pct($t['free']), 'tone' => 'warn'],
            ['label' => 'Retirada na loja', 'value' => Format::show('int', $t['pickup']), 'note' => $pct($t['pickup'])],
            ['label' => 'Prazo médio prometido', 'value' => $t['days'] === null ? '—' : Format::show('qty', $t['days']) . ' dia(s)', 'note' => 'o prazo mostrado ao cliente'],
        ];
    }

    public function note(array $t, array $f): string
    {
        return 'Pedidos pagos, pela forma de entrega e o frete gravados no pedido. Frete grátis = frete zero (inclui a retirada na loja). O prazo é o prometido no pedido, não o tempo real de entrega.';
    }
}
