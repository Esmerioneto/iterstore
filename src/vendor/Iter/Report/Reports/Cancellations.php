<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;
use Iter\Sales\Model\OrderService;

/** V7: the orders canceled in the period (by the cancellation date), per reason: how many, how much, and what had been paid */
final class Cancellations extends Report
{
    public function filters(): array
    {
        return ['reason' => ['label' => 'Motivo', 'options' => ['' => 'Todos os motivos'] + OrderService::CANCEL_REASONS + ['none' => 'Não informado']]];
    }

    public function columns(array $f): array
    {
        return [
            'reason' => ['label' => 'Motivo', 'type' => 'text', 'link' => '/admin/order/index?status=canceled&cancel_reason={reason_code}'],
            'orders' => ['label' => 'Pedidos', 'type' => 'int', 'sum' => true],
            'share' => ['label' => '% dos cancelamentos', 'type' => 'pct', 'sum' => true],
            'total' => ['label' => 'Valor', 'type' => 'money', 'sum' => true],
            'paid' => ['label' => 'Já pagos', 'type' => 'int', 'sum' => true],
            'refunded' => ['label' => 'Estornado no Mercado Pago', 'type' => 'money', 'sum' => true],
        ];
    }

    public function sort(): array
    {
        return ['orders', 'desc'];
    }

    public function lines(array $f): array
    {
        // when it was canceled: the history line; the last update for an order without one
        $date = "COALESCE((SELECT MAX(h.created_at) FROM sales_order_status_history h WHERE h.order_id = o.order_id AND h.status = 'canceled'), o.updated_at)";
        [$where, $bind] = self::orderWhere($f, 'o', $date);
        if ($f['reason'] === 'none') {
            $where .= ' AND o.cancel_reason IS NULL';
        } elseif ($f['reason'] !== '') {
            $where .= ' AND o.cancel_reason = :reason';
            $bind['reason'] = $f['reason'];
        }
        $label = "CASE o.cancel_reason";
        foreach (OrderService::CANCEL_REASONS as $code => $text) {
            $label .= " WHEN :r_{$code} THEN :l_{$code}";
            $bind["r_{$code}"] = $code;
            $bind["l_{$code}"] = $text;
        }
        $label .= " ELSE 'Não informado' END";
        $refunded = \isModuleEnabled('iter_mercadopago')
            ? "COALESCE(SUM((SELECT SUM(m.amount) FROM mercadopago_payment m WHERE m.order_id = o.order_id AND m.status = 'refunded')), 0)"
            : '0';

        return ["SELECT o.cancel_reason AS reason_code, MAX({$label}) AS reason, COUNT(*) AS orders,
                COUNT(*)::numeric / NULLIF(SUM(COUNT(*)) OVER (), 0) AS share, SUM(o.grand_total) AS total,
                COUNT(*) FILTER (WHERE EXISTS (SELECT 1 FROM sales_order_status_history h WHERE h.order_id = o.order_id AND h.status = 'processing')) AS paid,
                {$refunded} AS refunded
            FROM sales_order o
            WHERE o.status = 'canceled' AND {$where}
            GROUP BY o.cancel_reason", $bind];
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        return [
            ['label' => 'Cancelados', 'value' => Format::show('int', $t['orders']), 'note' => Format::show('money', $t['total']), 'tone' => 'bad'],
            ['label' => 'Já estavam pagos', 'value' => Format::show('int', $t['paid']), 'tone' => 'warn'],
            ['label' => 'Estornado no Mercado Pago', 'value' => Format::show('money', $t['refunded'])],
        ];
    }

    public function note(array $t, array $f): string
    {
        return 'Pela data do cancelamento. "Já pagos" chegaram a ter o pagamento confirmado: o valor foi estornado no Mercado Pago ou devolvido como está no histórico de cada pedido. O motivo abre a lista desses pedidos.';
    }
}
