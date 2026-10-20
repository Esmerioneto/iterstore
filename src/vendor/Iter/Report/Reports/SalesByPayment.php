<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/** V4: per payment method of the paid orders; with Mercado Pago, the average installments and the card brands */
final class SalesByPayment extends Report
{
    public function columns(array $f): array
    {
        return [
            'method' => ['label' => 'Forma de pagamento', 'type' => 'text'],
            'orders' => ['label' => 'Pedidos', 'type' => 'int', 'sum' => true],
            'total' => ['label' => 'Valor', 'type' => 'money', 'sum' => true],
            'share' => ['label' => '% do valor', 'type' => 'pct', 'sum' => true],
            'ticket' => ['label' => 'Ticket médio', 'type' => 'money'],
            'installments' => ['label' => 'Parcelas (média)', 'type' => 'text'],
            'brands' => ['label' => 'Bandeiras', 'type' => 'text'],
        ];
    }

    public function sort(): array
    {
        return ['total', 'desc'];
    }

    public function lines(array $f): array
    {
        [$where, $bind] = self::orderWhere($f);
        // the approved charge of each order (an order may have refused attempts before it)
        $charges = "SELECT DISTINCT ON (m.order_id) m.order_id, m.installments, m.card_brand FROM mercadopago_payment m WHERE m.status IN ('approved', 'refunded') ORDER BY m.order_id, m.payment_id DESC";
        $mercadopago = \isModuleEnabled('iter_mercadopago');

        return ["SELECT COALESCE(MAX(o.payment_label), 'Sem forma de pagamento') AS method, COUNT(*) AS orders, SUM(o.grand_total) AS total,
                SUM(o.grand_total) / NULLIF(SUM(SUM(o.grand_total)) OVER (), 0) AS share,
                SUM(o.grand_total) / COUNT(*) AS ticket,
                " . ($mercadopago ? "REPLACE(ROUND(AVG(m.installments) FILTER (WHERE m.installments > 0), 1)::text, '.', ',')" : 'NULL') . " AS installments,
                " . ($mercadopago ? "STRING_AGG(DISTINCT REGEXP_REPLACE(INITCAP(m.card_brand), '^Master$', 'Mastercard'), ', ')" : 'NULL') . " AS brands
            FROM sales_order o
            " . ($mercadopago ? "LEFT JOIN ({$charges}) m ON m.order_id = o.order_id" : '') . "
            WHERE " . self::paid() . " AND {$where}
            GROUP BY o.payment_method", $bind];
    }

    public function totals(array $t, array $f): array
    {
        $t['ticket'] = (int) $t['orders'] > 0 ? (float) $t['total'] / (int) $t['orders'] : null;

        return $t;
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        return [
            ['label' => 'Recebido', 'value' => Format::show('money', $t['total']), 'note' => 'pedidos pagos, com frete'],
            ['label' => 'Pedidos pagos', 'value' => Format::show('int', $t['orders'])],
            ['label' => 'Ticket médio', 'value' => Format::show('money', $t['ticket'])],
        ];
    }

    public function note(array $t, array $f): string
    {
        return 'Pedidos pagos, pela forma escolhida no pedido. Parcelas e bandeiras vêm da cobrança aprovada no Mercado Pago.';
    }
}
