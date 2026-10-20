<?php

declare(strict_types=1);

namespace Iter\Promotion\Reports;

use Iter\Core\Db\Connection;
use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/**
 * V8: per promotion and coupon, the paid orders that had it: uses, the discount given and what those
 * orders sold, with the ticket of the orders with a discount against the ones without.
 */
final class PromotionUsage extends Report
{
    public function filters(): array
    {
        return [
            'promotion_id' => ['label' => 'Promoção', 'options' => ['' => 'Todas as promoções'] + array_map('strval', Connection::get()->adapter()->fetchPairs('SELECT promotion_id, name FROM promotion ORDER BY name'))],
            'kind' => ['label' => 'Tipo', 'options' => ['' => 'Automáticas e cupons', 'auto' => 'Só automáticas', 'coupon' => 'Só cupons']],
            'q' => ['label' => 'Cupom', 'type' => 'search', 'placeholder' => 'Código do cupom'],
        ];
    }

    public function columns(array $f): array
    {
        return [
            'promotion' => ['label' => 'Promoção', 'type' => 'text', 'link' => '/admin/promotion/edit?id={promotion_id}'],
            'coupon' => ['label' => 'Cupom', 'type' => 'text'],
            'orders' => ['label' => 'Usos (pedidos)', 'type' => 'int', 'sum' => true],
            'discount' => ['label' => 'Desconto concedido', 'type' => 'money', 'sum' => true],
            'revenue' => ['label' => 'Faturamento dos pedidos', 'type' => 'money', 'sum' => true],
            'ticket' => ['label' => 'Ticket médio', 'type' => 'money'],
            'discount_pct' => ['label' => 'Desconto / faturamento', 'type' => 'pct'],
            'plain_orders' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'plain_total' => ['label' => '', 'type' => 'money', 'sum' => true, 'hidden' => true],
        ];
    }

    public function sort(): array
    {
        return ['discount', 'desc'];
    }

    public function lines(array $f): array
    {
        [$where, $bind] = $this->where($f);

        return ["SELECT d.promotion_id, MAX(d.promotion_name) AS promotion, COALESCE(UPPER(d.coupon_code), 'Automática') AS coupon,
                COUNT(DISTINCT o.order_id) AS orders, SUM(d.discount_amount) AS discount, SUM(o.grand_total) AS revenue,
                SUM(o.grand_total) / COUNT(DISTINCT o.order_id) AS ticket, SUM(d.discount_amount) / NULLIF(SUM(o.grand_total), 0) AS discount_pct,
                0 AS plain_orders, 0 AS plain_total
            FROM sales_order_discount d
            JOIN sales_order o ON o.order_id = d.order_id
            WHERE " . self::paid() . " AND {$where}
            GROUP BY d.promotion_id, COALESCE(UPPER(d.coupon_code), 'Automática')", $bind];
    }

    /** one line per paid order: an order with two promotions is one use in the totals, and the ones without any give the ticket without discount */
    public function totalsLines(array $f): ?array
    {
        [$where, $bind] = $this->where($f);
        [$all, $allBind] = self::orderWhere($f);

        return ["SELECT CASE WHEN d.order_id IS NULL THEN 0 ELSE 1 END AS orders, COALESCE(d.discount, 0) AS discount,
                CASE WHEN d.order_id IS NULL THEN 0 ELSE o.grand_total END AS revenue,
                CASE WHEN d.order_id IS NULL AND NOT EXISTS (SELECT 1 FROM sales_order_discount x WHERE x.order_id = o.order_id) THEN 1 ELSE 0 END AS plain_orders,
                CASE WHEN d.order_id IS NULL AND NOT EXISTS (SELECT 1 FROM sales_order_discount x WHERE x.order_id = o.order_id) THEN o.grand_total ELSE 0 END AS plain_total
            FROM sales_order o
            LEFT JOIN (SELECT d.order_id, SUM(d.discount_amount) AS discount FROM sales_order_discount d JOIN sales_order o ON o.order_id = d.order_id
                WHERE " . self::paid() . " AND {$where} GROUP BY d.order_id) d ON d.order_id = o.order_id
            WHERE " . self::paid() . " AND {$all}", $bind + $allBind];
    }

    public function totals(array $t, array $f): array
    {
        $t['ticket'] = (int) $t['orders'] > 0 ? (float) $t['revenue'] / (int) $t['orders'] : null;
        $t['discount_pct'] = (float) $t['revenue'] > 0 ? (float) $t['discount'] / (float) $t['revenue'] : null;

        return $t;
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        $plain = (int) $t['plain_orders'] > 0 ? (float) $t['plain_total'] / (int) $t['plain_orders'] : null;

        return [
            ['label' => 'Desconto concedido', 'value' => Format::show('money', $t['discount']), 'note' => Format::show('int', $t['orders']) . ' pedido(s) pago(s) com desconto', 'tone' => 'warn'],
            ['label' => 'Faturamento com desconto', 'value' => Format::show('money', $t['revenue']), 'note' => $t['discount_pct'] === null ? '' : 'desconto de ' . Format::show('pct', $t['discount_pct'])],
            ['label' => 'Ticket com desconto', 'value' => Format::show('money', $t['ticket']), 'note' => 'sem desconto: ' . Format::show('money', $plain) . ' (' . Format::show('int', $t['plain_orders']) . ' pedido(s))'],
        ];
    }

    public function note(array $t, array $f): string
    {
        return 'Pedidos pagos, com o nome da promoção e o desconto gravados no pedido. Um pedido com duas promoções aparece nas duas linhas e conta uma vez no total. "Sem desconto" são os pedidos pagos do período sem promoção nem cupom (com os filtros de promoção, continuam sendo todos os sem desconto).';
    }

    /** the promotion filters on d (sales_order_discount) and the common ones on o */
    private function where(array $f): array
    {
        [$where, $bind] = self::orderWhere($f);
        if ($f['promotion_id'] !== '') {
            $where .= ' AND d.promotion_id = :promotion_id';
            $bind['promotion_id'] = (int) $f['promotion_id'];
        }
        $where .= match ($f['kind']) {
            'auto' => ' AND d.coupon_code IS NULL',
            'coupon' => ' AND d.coupon_code IS NOT NULL',
            default => '',
        };
        if ($f['q'] !== '') {
            $where .= ' AND d.coupon_code ILIKE :q';
            $bind['q'] = '%' . addcslashes($f['q'], '%_\\') . '%';
        }

        return [$where, $bind];
    }
}
