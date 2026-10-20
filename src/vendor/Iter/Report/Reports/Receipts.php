<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/**
 * F1: what came in by the payment date (the first "Pagamento confirmado"), per payment method: the
 * gross, what went back on the ones canceled after being paid (refunded at Mercado Pago or another way)
 * and the net.
 */
final class Receipts extends Report
{
    public function common(): array
    {
        return ['period', 'website_id', 'sales_channel', 'payment_method'];
    }

    public function columns(array $f): array
    {
        return [
            'method' => ['label' => 'Forma de pagamento', 'type' => 'text'],
            'orders' => ['label' => 'Pagamentos', 'type' => 'int', 'sum' => true],
            'gross' => ['label' => 'Recebido (bruto)', 'type' => 'money', 'sum' => true],
            'canceled' => ['label' => 'Cancelados depois de pagos', 'type' => 'int', 'sum' => true],
            'refunded_mp' => ['label' => 'Estornado no Mercado Pago', 'type' => 'money', 'sum' => true],
            'returned' => ['label' => 'Devolvido de outro jeito', 'type' => 'money', 'sum' => true],
            'net' => ['label' => 'Líquido', 'type' => 'money', 'sum' => true],
            'share' => ['label' => '% do líquido', 'type' => 'pct', 'sum' => true],
        ];
    }

    public function sort(): array
    {
        return ['gross', 'desc'];
    }

    public function lines(array $f): array
    {
        $paidAt = "(SELECT MIN(h.created_at) FROM sales_order_status_history h WHERE h.order_id = o.order_id AND h.status = 'processing')";
        [$where, $bind] = self::orderWhere($f, 'o', 'p.at');
        $refunded = \isModuleEnabled('iter_mercadopago')
            ? "COALESCE((SELECT SUM(m.amount) FROM mercadopago_payment m WHERE m.order_id = o.order_id AND m.status = 'refunded'), 0)"
            : '0';

        // canceled after paid: the Mercado Pago refund, and the rest of the order taken as given back another way (the cancel screen asks how)
        return ["SELECT COALESCE(MAX(x.payment_label), 'Sem forma de pagamento') AS method, COUNT(*) AS orders, SUM(x.grand_total) AS gross,
                COUNT(*) FILTER (WHERE x.status = 'canceled') AS canceled,
                SUM(x.refunded) AS refunded_mp,
                SUM(CASE WHEN x.status = 'canceled' THEN GREATEST(x.grand_total - x.refunded, 0) ELSE 0 END) AS returned,
                SUM(CASE WHEN x.status = 'canceled' THEN 0 ELSE x.grand_total END) AS net,
                SUM(CASE WHEN x.status = 'canceled' THEN 0 ELSE x.grand_total END) / NULLIF(SUM(SUM(CASE WHEN x.status = 'canceled' THEN 0 ELSE x.grand_total END)) OVER (), 0) AS share
            FROM (SELECT o.payment_method, o.payment_label, o.status, o.grand_total, CASE WHEN o.status = 'canceled' THEN {$refunded} ELSE 0 END AS refunded
                FROM sales_order o CROSS JOIN LATERAL (SELECT {$paidAt} AS at) p
                WHERE p.at IS NOT NULL AND {$where}) x
            GROUP BY x.payment_method", $bind];
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        return [
            ['label' => 'Recebido (bruto)', 'value' => Format::show('money', $t['gross']), 'note' => Format::show('int', $t['orders']) . ' pagamento(s) confirmado(s)'],
            ['label' => 'Devolvido', 'value' => Format::show('money', (float) $t['refunded_mp'] + (float) $t['returned']), 'note' => Format::show('int', $t['canceled']) . ' pedido(s) cancelado(s) depois de pagos', 'tone' => 'bad'],
            ['label' => 'Líquido', 'value' => Format::show('money', $t['net']), 'note' => 'bruto − devolvido', 'tone' => 'ok'],
        ];
    }

    public function note(array $t, array $f): string
    {
        return 'Pela data em que o pagamento foi confirmado (o pedido pode ser de outro dia), com o valor total do pedido. Um pedido cancelado depois de pago entra no bruto e sai no líquido: a parte estornada no Mercado Pago aparece à parte, e o resto conta como devolvido de outro jeito (como informado no cancelamento). A taxa do Mercado Pago não entra.';
    }
}
