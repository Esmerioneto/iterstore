<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/** E3: active SKUs with something to sell and no paid sale in the period, with the stock standing still and the days since the last sale */
final class NoSales extends Report
{
    public function common(): array
    {
        return ['period', 'website_id'];
    }

    public function preset(): string
    {
        return '90d';
    }

    public function filters(): array
    {
        return [
            'category_id' => ['label' => 'Categoria', 'options' => self::categories()],
            'q' => ['label' => 'Busca', 'type' => 'search', 'placeholder' => 'SKU ou nome'],
        ];
    }

    public function columns(array $f): array
    {
        return [
            'sku' => ['label' => 'SKU', 'type' => 'text', 'link' => '/admin/product/edit?id={edit_id}'],
            'name' => ['label' => 'Produto', 'type' => 'text'],
            'salable' => ['label' => 'Vendável', 'type' => 'qty', 'sum' => true],
            'price' => ['label' => 'Preço', 'type' => 'money'],
            'value_cost' => ['label' => 'Parado pelo custo', 'type' => 'money', 'sum' => true, 'cost' => true],
            'value_price' => ['label' => 'Parado pelo preço', 'type' => 'money', 'sum' => true],
            'created_at' => ['label' => 'Cadastrado em', 'type' => 'date'],
            'last_sale' => ['label' => 'Última venda', 'type' => 'date'],
            'days' => ['label' => 'Dias sem venda', 'type' => 'int'],
            'lines' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'never' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
        ];
    }

    public function sort(): array
    {
        return ['days', 'desc'];
    }

    public function lines(array $f): array
    {
        $bind = ['from' => $f['from'], 'to_next' => $f['to_next']];
        $where = self::productWhere($f, $bind);
        $website = $f['website_id'] ? ' AND o.website_id = ' . (int) $f['website_id'] : '';
        $sales = 'FROM sales_order_item it JOIN sales_order o ON o.order_id = it.order_id WHERE it.product_id = p.product_id AND ' . self::paid() . $website;

        return ["SELECT x.*, CURRENT_DATE - COALESCE(x.last_sale, x.created_at)::date AS days,
                CASE WHEN x.last_sale IS NULL THEN 1 ELSE 0 END AS never
            FROM (SELECT p.sku, p.name, COALESCE(p.parent_id, p.product_id) AS edit_id, p.created_at, 1 AS lines,
                    st.salable, pr.value AS price, st.salable * c.value AS value_cost, st.salable * pr.value AS value_price,
                    (SELECT MAX(o.created_at) {$sales}) AS last_sale
                FROM catalog_product p
                LEFT JOIN catalog_product pp ON pp.product_id = p.parent_id
                " . self::attribute('pr', 'price') . '
                ' . self::attribute('c', 'cost') . '
                LEFT JOIN LATERAL (' . self::stock($f) . ") st ON TRUE
                WHERE p.type <> 'configurable' AND p.is_active = 1 AND COALESCE(pp.is_active, 1) = 1 AND st.salable > 0
                    AND NOT EXISTS (SELECT 1 {$sales} AND o.created_at >= :from AND o.created_at < :to_next){$where}) x", $bind];
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        $tiles = [
            ['label' => 'SKUs sem venda', 'value' => Format::show('int', $t['lines']), 'note' => Format::show('int', $t['never']) . ' nunca vendido(s)', 'tone' => 'warn'],
            ['label' => 'Unidades paradas', 'value' => Format::show('qty', $t['salable']), 'note' => 'vendável de hoje'],
            ['label' => 'Parado pelo preço', 'value' => Format::show('money', $t['value_price']), 'note' => 'vendável × preço de hoje'],
        ];
        if ($f['cost']) {
            $tiles[] = ['label' => 'Parado pelo custo', 'value' => Format::show('money', $t['value_cost']), 'note' => 'dos SKUs com custo'];
        }

        return $tiles;
    }

    public function note(array $t, array $f): string
    {
        return 'Produtos ativos com vendável hoje e nenhuma unidade em pedido pago no período. "Dias sem venda" conta desde a última venda paga (de qualquer data) ou, se nunca vendeu, desde o cadastro.';
    }
}
