<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/** V2: per product (or per variant), what the paid orders sold: quantity, revenue after discounts, cost and margin */
final class SalesByProduct extends Report
{
    public function filters(): array
    {
        return [
            'category_id' => ['label' => 'Categoria', 'options' => self::categories()],
            'q' => ['label' => 'Busca', 'type' => 'search', 'placeholder' => 'SKU ou nome'],
            'parent' => ['label' => 'Somar variações no produto pai', 'type' => 'checkbox', 'default' => true],
        ];
    }

    public function columns(array $f): array
    {
        return [
            'sku' => ['label' => 'SKU', 'type' => 'text'],
            'name' => ['label' => 'Produto', 'type' => 'text'],
            'qty' => ['label' => 'Qtd.', 'type' => 'qty', 'sum' => true],
            'orders' => ['label' => 'Pedidos', 'type' => 'int'], // an order with two products counts in both: no sum
            'revenue' => ['label' => 'Faturamento', 'type' => 'money', 'sum' => true],
            'discount' => ['label' => 'Desconto', 'type' => 'money', 'sum' => true],
            'cost' => ['label' => 'Custo', 'type' => 'money', 'sum' => true, 'cost' => true],
            'margin' => ['label' => 'Margem', 'type' => 'money', 'sum' => true, 'cost' => true],
            'margin_pct' => ['label' => 'Margem %', 'type' => 'pct', 'cost' => true],
            'avg_price' => ['label' => 'Preço médio', 'type' => 'money'],
            'share' => ['label' => '% do total', 'type' => 'pct', 'sum' => true],
            'net_cost' => ['label' => '', 'type' => 'money', 'sum' => true, 'hidden' => true],
            'lines' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
        ];
    }

    public function sort(): array
    {
        return ['revenue', 'desc'];
    }

    public function lines(array $f): array
    {
        [$where, $bind] = self::orderWhere($f);
        // a deleted product keeps the SKU and name the order copied
        $key = $f['parent'] ? 'COALESCE(pp.sku, p.sku, it.sku)' : 'it.sku';
        $name = $f['parent'] ? 'MAX(COALESCE(pp.name, p.name, it.name))' : 'MAX(it.name)';
        if ($f['category_id'] !== '') {
            $where .= ' AND EXISTS (SELECT 1 FROM catalog_category_product cp WHERE cp.product_id = COALESCE(pp.product_id, p.product_id) AND cp.category_id = :category_id)';
            $bind['category_id'] = (int) $f['category_id'];
        }
        if ($f['q'] !== '') {
            $where .= " AND CONCAT_WS(' ', it.sku, it.name, pp.sku, pp.name) ILIKE :q";
            $bind['q'] = '%' . addcslashes($f['q'], '%_\\') . '%';
        }

        return ["SELECT {$key} AS sku, {$name} AS name, 1 AS lines,
                SUM(it.quantity) AS qty, COUNT(DISTINCT o.order_id) AS orders,
                SUM(it.row_total - it.discount_amount) AS revenue, SUM(it.discount_amount) AS discount,
                SUM(it.cost * it.quantity) AS cost,
                SUM(it.row_total - it.discount_amount) FILTER (WHERE it.cost IS NOT NULL) AS net_cost,
                SUM(it.row_total - it.discount_amount) FILTER (WHERE it.cost IS NOT NULL) - SUM(it.cost * it.quantity) AS margin,
                (SUM(it.row_total - it.discount_amount) FILTER (WHERE it.cost IS NOT NULL) - SUM(it.cost * it.quantity))
                    / NULLIF(SUM(it.row_total - it.discount_amount) FILTER (WHERE it.cost IS NOT NULL), 0) AS margin_pct,
                SUM(it.row_total - it.discount_amount) / NULLIF(SUM(it.quantity), 0) AS avg_price,
                SUM(it.row_total - it.discount_amount) / NULLIF(SUM(SUM(it.row_total - it.discount_amount)) OVER (), 0) AS share
            FROM sales_order_item it
            JOIN sales_order o ON o.order_id = it.order_id
            LEFT JOIN catalog_product p ON p.product_id = it.product_id
            LEFT JOIN catalog_product pp ON pp.product_id = p.parent_id
            WHERE " . self::paid() . " AND {$where}
            GROUP BY {$key}", $bind];
    }

    public function totals(array $t, array $f): array
    {
        $t = self::margin($t);
        $t['avg_price'] = (float) $t['qty'] > 0 ? (float) $t['revenue'] / (float) $t['qty'] : null;

        return $t;
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        $gross = (float) $t['revenue'] + (float) $t['discount'];
        $tiles = [
            ['label' => 'Faturamento', 'value' => Format::show('money', $t['revenue']), 'note' => 'itens, sem frete'],
            ['label' => 'Unidades', 'value' => Format::show('qty', $t['qty']), 'note' => Format::show('int', $t['lines']) . ($f['parent'] ? ' produto(s)' : ' SKU(s)')],
            ['label' => 'Descontos', 'value' => Format::show('money', $t['discount']), 'note' => $gross > 0 ? Format::show('pct', (float) $t['discount'] / $gross) . ' do bruto' : ''],
        ];
        if ($f['cost']) {
            $tiles[] = ['label' => 'Custo', 'value' => Format::show('money', $t['cost']), 'note' => 'dos itens com custo', 'tone' => 'warn'];
            $tiles[] = ['label' => 'Margem bruta', 'value' => Format::show('money', $t['margin']), 'note' => $t['margin_pct'] === null ? 'sem itens com custo' : Format::show('pct', $t['margin_pct']) . ' dos itens com custo', 'tone' => 'ok'];
        }
        $tiles[] = ['label' => 'Preço médio', 'value' => Format::show('money', $t['avg_price']), 'note' => 'por unidade'];

        return $tiles;
    }

    public function note(array $t, array $f): string
    {
        return 'Pedidos pagos. Faturamento = o que os itens venderam, já com os descontos, sem o frete.'
            . ($f['cost'] ? ' Margem = faturamento − custo × quantidade, com o custo que o produto tinha no dia do pedido.' . self::withoutCost($t, 'revenue') : '');
    }
}
