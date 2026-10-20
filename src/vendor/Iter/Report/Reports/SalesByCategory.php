<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/**
 * V3: per category of the product (a variant counts in its parent's), revenue and margin. A product in two
 * categories counts in both (as on the dashboard), so the totals come from the items, not from the lines.
 */
final class SalesByCategory extends Report
{
    public function columns(array $f): array
    {
        return [
            'category' => ['label' => 'Categoria', 'type' => 'text'],
            'qty' => ['label' => 'Qtd.', 'type' => 'qty', 'sum' => true],
            'orders' => ['label' => 'Pedidos', 'type' => 'int'],
            'revenue' => ['label' => 'Faturamento', 'type' => 'money', 'sum' => true],
            'cost' => ['label' => 'Custo', 'type' => 'money', 'sum' => true, 'cost' => true],
            'margin' => ['label' => 'Margem', 'type' => 'money', 'sum' => true, 'cost' => true],
            'margin_pct' => ['label' => 'Margem %', 'type' => 'pct', 'cost' => true],
            'share' => ['label' => '% do faturamento', 'type' => 'pct'],
            'net_cost' => ['label' => '', 'type' => 'money', 'sum' => true, 'hidden' => true],
        ];
    }

    public function sort(): array
    {
        return ['revenue', 'desc'];
    }

    /** the paid items with their product (the parent for a variant), the period's other filters applied */
    private static function items(array $f): array
    {
        [$where, $bind] = self::orderWhere($f);

        return ["SELECT it.*, COALESCE(p.parent_id, it.product_id) AS pid, it.row_total - it.discount_amount AS net
            FROM sales_order_item it
            JOIN sales_order o ON o.order_id = it.order_id
            LEFT JOIN catalog_product p ON p.product_id = it.product_id
            WHERE " . self::paid() . " AND {$where}", $bind];
    }

    public function lines(array $f): array
    {
        [$items, $bind] = self::items($f);

        return ["WITH RECURSIVE tree AS (
                SELECT category_id, name::text AS path FROM catalog_category WHERE parent_id IS NULL
                UNION ALL
                SELECT c.category_id, t.path || ' › ' || c.name FROM catalog_category c JOIN tree t ON c.parent_id = t.category_id
            ), items AS ({$items})
            SELECT tree.path AS category, SUM(i.quantity) AS qty, COUNT(DISTINCT i.order_id) AS orders, SUM(i.net) AS revenue,
                SUM(i.cost * i.quantity) AS cost,
                SUM(i.net) FILTER (WHERE i.cost IS NOT NULL) AS net_cost,
                SUM(i.net) FILTER (WHERE i.cost IS NOT NULL) - SUM(i.cost * i.quantity) AS margin,
                (SUM(i.net) FILTER (WHERE i.cost IS NOT NULL) - SUM(i.cost * i.quantity)) / NULLIF(SUM(i.net) FILTER (WHERE i.cost IS NOT NULL), 0) AS margin_pct,
                SUM(i.net) / NULLIF((SELECT SUM(net) FROM items), 0) AS share
            FROM items i
            JOIN catalog_category_product cp ON cp.product_id = i.pid
            JOIN tree ON tree.category_id = cp.category_id
            GROUP BY tree.category_id, tree.path", $bind];
    }

    public function totalsLines(array $f): ?array
    {
        [$items, $bind] = self::items($f);

        return ["SELECT quantity AS qty, net AS revenue, cost * quantity AS cost, CASE WHEN cost IS NOT NULL THEN net END AS net_cost,
                CASE WHEN cost IS NOT NULL THEN net - cost * quantity END AS margin
            FROM ({$items}) i", $bind];
    }

    public function totals(array $t, array $f): array
    {
        return self::margin($t);
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        $tiles = [
            ['label' => 'Faturamento', 'value' => Format::show('money', $t['revenue']), 'note' => 'itens, sem frete'],
            ['label' => 'Unidades', 'value' => Format::show('qty', $t['qty'])],
        ];
        if ($f['cost']) {
            $tiles[] = ['label' => 'Margem bruta', 'value' => Format::show('money', $t['margin']), 'note' => $t['margin_pct'] === null ? 'sem itens com custo' : Format::show('pct', $t['margin_pct']) . ' dos itens com custo', 'tone' => 'ok'];
        }

        return $tiles;
    }

    public function note(array $t, array $f): string
    {
        return 'Pedidos pagos, pela categoria do produto (a variação conta na do produto pai). Um produto em duas categorias conta nas duas, então as linhas somam mais que o total; o total é o dos itens, sem repetir.'
            . ($f['cost'] ? self::withoutCost($t, 'revenue') : '');
    }
}
