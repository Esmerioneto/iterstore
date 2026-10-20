<?php

declare(strict_types=1);

namespace Iter\Mercadopago\Reports;

use Iter\Mercadopago\Model\MercadopagoService;
use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/**
 * F2: the Mercado Pago charges of the period against the orders: per situation, reason and card brand
 * (approved, refused and why, refunded), or the list of what needs attention: an approved charge on a
 * canceled order (to refund).
 */
final class Reconciliation extends Report
{
    public function common(): array
    {
        return ['period', 'website_id', 'payment_method'];
    }

    public function filters(): array
    {
        return ['view' => ['label' => 'Mostrar', 'options' => ['summary' => 'Por situação, motivo e bandeira', 'attention' => 'A estornar (aprovado em pedido cancelado)']]];
    }

    public function columns(array $f): array
    {
        $list = $f['view'] === 'attention';

        return array_filter([
            'created_at' => $list ? ['label' => 'Data', 'type' => 'datetime'] : null,
            'number' => $list ? ['label' => 'Pedido', 'type' => 'text', 'link' => '/admin/order/view?id={order_id}'] : null,
            'situation' => ['label' => 'Situação', 'type' => 'text'],
            'reason' => $list ? null : ['label' => 'Motivo', 'type' => 'text'],
            'brand' => ['label' => 'Forma / bandeira', 'type' => 'text'],
            'charges' => ['label' => 'Cobranças', 'type' => 'int', 'sum' => true, 'hidden' => $list] + ($list ? ['sort' => 'created_at'] : []),
            'amount' => ['label' => 'Valor', 'type' => 'money', 'sum' => !$list],
            'share' => $list ? null : ['label' => '% das tentativas', 'type' => 'pct'],
            'approved' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'approved_amount' => ['label' => '', 'type' => 'money', 'sum' => true, 'hidden' => true],
            'rejected' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'refunded_amount' => ['label' => '', 'type' => 'money', 'sum' => true, 'hidden' => true],
            'attention' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'attention_amount' => ['label' => '', 'type' => 'money', 'sum' => true, 'hidden' => true],
        ]);
    }

    public function sort(): array
    {
        return ['charges', 'desc'];
    }

    public function lines(array $f): array
    {
        [$sql, $bind] = $this->charges($f);
        if ($f['view'] === 'attention') {
            return ["SELECT c.*, 1 AS charges FROM ({$sql}) c WHERE c.attention = 1", $bind];
        }

        return ["SELECT c.situation, c.reason, c.brand, COUNT(*) AS charges, SUM(c.amount) AS amount, COUNT(*)::numeric / NULLIF(SUM(COUNT(*)) OVER (), 0) AS share,
                SUM(c.approved) AS approved, SUM(c.approved_amount) AS approved_amount, SUM(c.rejected) AS rejected,
                SUM(c.refunded_amount) AS refunded_amount, SUM(c.attention) AS attention, SUM(c.attention_amount) AS attention_amount
            FROM ({$sql}) c GROUP BY c.situation, c.reason, c.brand", $bind];
    }

    /** the indicators count every charge of the period, in both views */
    public function totalsLines(array $f): ?array
    {
        [$sql, $bind] = $this->charges($f);

        return ["SELECT c.*, 1 AS charges FROM ({$sql}) c", $bind];
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        $charges = (int) $t['charges'];

        return [
            ['label' => 'Aprovado', 'value' => Format::show('money', $t['approved_amount']), 'note' => Format::show('int', $t['approved']) . ' cobrança(s)', 'tone' => 'ok'],
            ['label' => 'Recusadas', 'value' => Format::show('int', $t['rejected']), 'note' => $charges ? Format::show('pct', (int) $t['rejected'] / $charges) . ' das tentativas' : ''],
            ['label' => 'Estornado', 'value' => Format::show('money', $t['refunded_amount']), 'note' => 'devolvido pelo Mercado Pago'],
            ['label' => 'A estornar', 'value' => Format::show('int', $t['attention']), 'note' => Format::show('money', $t['attention_amount']) . ' aprovado(s) em pedido cancelado'] + ((int) $t['attention'] ? ['tone' => 'bad'] : []),
        ];
    }

    public function note(array $t, array $f): string
    {
        return 'Cada tentativa de cobrança no Mercado Pago pela data em que foi criada (um pedido pode ter recusas antes de ser aprovado). "A estornar": cobrança aprovada cujo pedido foi cancelado sem estorno; confira no painel do Mercado Pago e estorne. A taxa do Mercado Pago não entra.';
    }

    /** one line per charge: what it is, and its flags for the indicators */
    private function charges(array $f): array
    {
        [$where, $bind] = self::orderWhere($f, 'o', 'm.created_at');
        $situation = 'CASE m.status';
        foreach (MercadopagoService::STATUS_LABELS as $code => $label) {
            $situation .= " WHEN :s_{$code} THEN :sl_{$code}";
            $bind["s_{$code}"] = $code;
            $bind["sl_{$code}"] = $label;
        }
        $detail = "REGEXP_REPLACE(COALESCE(m.status_detail, ''), '^cc_rejected_', '')";
        $reason = "CASE {$detail}";
        foreach (MercadopagoService::REASONS as $code => $label) {
            $reason .= " WHEN :r_{$code} THEN :rl_{$code}";
            $bind["r_{$code}"] = $code;
            $bind["rl_{$code}"] = $label;
        }
        $methods = 'CASE m.method';
        foreach (MercadopagoService::METHODS as $code => $label) {
            $methods .= " WHEN :m_{$code} THEN :ml_{$code}";
            $bind["m_{$code}"] = $code;
            $bind["ml_{$code}"] = $label;
        }

        return ["SELECT m.created_at, o.order_id, o.increment_id AS number, m.amount,
                {$situation} ELSE m.status END AS situation,
                {$reason} ELSE NULLIF({$detail}, '') END AS reason,
                COALESCE(REGEXP_REPLACE(INITCAP(m.card_brand), '^Master$', 'Mastercard'), {$methods} ELSE m.method END) AS brand,
                CASE WHEN m.status = 'approved' THEN 1 ELSE 0 END AS approved,
                CASE WHEN m.status = 'approved' THEN m.amount ELSE 0 END AS approved_amount,
                CASE WHEN m.status = 'rejected' THEN 1 ELSE 0 END AS rejected,
                CASE WHEN m.status = 'refunded' THEN m.amount ELSE 0 END AS refunded_amount,
                CASE WHEN m.status = 'approved' AND o.status = 'canceled' THEN 1 ELSE 0 END AS attention,
                CASE WHEN m.status = 'approved' AND o.status = 'canceled' THEN m.amount ELSE 0 END AS attention_amount
            FROM mercadopago_payment m
            JOIN sales_order o ON o.order_id = m.order_id
            WHERE {$where}", $bind];
    }
}
