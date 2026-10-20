<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/** E1: per SKU (simple products and variants), what the sources hold now, what orders hold and what is left to sell, and its value by cost and by price */
final class StockPosition extends Report
{
    public function common(): array
    {
        return ['website_id'];
    }

    public function filters(): array
    {
        return [
            'category_id' => ['label' => 'Categoria', 'options' => self::categories()],
            'q' => ['label' => 'Busca', 'type' => 'search', 'placeholder' => 'SKU ou nome'],
            'only' => ['label' => 'Produtos', 'options' => ['stock' => 'Só com estoque físico', 'all' => 'Todos os produtos']],
        ];
    }

    public function columns(array $f): array
    {
        return [
            'sku' => ['label' => 'SKU', 'type' => 'text', 'link' => '/admin/product/edit?id={edit_id}'],
            'name' => ['label' => 'Produto', 'type' => 'text'],
            'sources' => ['label' => 'Origens', 'type' => 'text'],
            'physical' => ['label' => 'Físico', 'type' => 'qty', 'sum' => true],
            'reserved' => ['label' => 'Reservado', 'type' => 'qty', 'sum' => true],
            'salable' => ['label' => 'Vendável', 'type' => 'qty', 'sum' => true],
            'price' => ['label' => 'Preço', 'type' => 'money'],
            'cost' => ['label' => 'Custo', 'type' => 'money', 'cost' => true],
            'value_cost' => ['label' => 'Valor pelo custo', 'type' => 'money', 'sum' => true, 'cost' => true],
            'value_price' => ['label' => 'Valor pelo preço', 'type' => 'money', 'sum' => true],
            'situation' => ['label' => 'Situação', 'type' => 'text'],
            'with_stock' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'no_cost' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
        ];
    }

    public function sort(): array
    {
        return ['sku', 'asc'];
    }

    public function lines(array $f): array
    {
        $bind = [];
        $where = self::productWhere($f, $bind) . ($f['only'] === 'stock' ? ' AND st.physical > 0' : '');

        return ["SELECT p.sku, p.name, COALESCE(p.parent_id, p.product_id) AS edit_id, st.sources, st.physical, st.reserved, st.salable,
                pr.value AS price, c.value AS cost, st.physical * c.value AS value_cost, st.physical * pr.value AS value_price,
                CASE WHEN p.is_active = 1 AND COALESCE(pp.is_active, 1) = 1 THEN 'Ativo' ELSE 'Inativo' END AS situation,
                CASE WHEN st.physical > 0 THEN 1 ELSE 0 END AS with_stock,
                CASE WHEN st.physical > 0 AND c.value IS NULL THEN 1 ELSE 0 END AS no_cost
            FROM catalog_product p
            LEFT JOIN catalog_product pp ON pp.product_id = p.parent_id
            " . self::attribute('pr', 'price') . '
            ' . self::attribute('c', 'cost') . '
            LEFT JOIN LATERAL (' . self::stock($f) . ") st ON TRUE
            WHERE p.type <> 'configurable'{$where}", $bind];
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        $tiles = [
            ['label' => 'SKUs com estoque', 'value' => Format::show('int', $t['with_stock']), 'note' => 'produtos simples e variações'],
            ['label' => 'Unidades', 'value' => Format::show('qty', $t['physical']), 'note' => Format::show('qty', $t['reserved']) . ' reservada(s) em pedidos'],
            ['label' => 'Valor pelo preço', 'value' => Format::show('money', $t['value_price']), 'note' => 'físico × preço de hoje', 'tone' => 'ok'],
        ];
        if ($f['cost']) {
            $tiles[] = ['label' => 'Valor pelo custo', 'value' => Format::show('money', $t['value_cost']), 'note' => (int) $t['no_cost'] ? Format::show('int', $t['no_cost']) . ' SKU(s) com estoque sem custo' : 'todos com custo'] + ((int) $t['no_cost'] ? ['tone' => 'warn'] : []);
        }

        return $tiles;
    }

    public function note(array $t, array $f): string
    {
        return 'A situação de agora, sem período. Físico = o que as origens ligadas dos websites do produto têm; reservado = o que pedidos ainda não enviados seguram; vendável = o físico marcado "em estoque" menos o reservado, o mesmo número da loja. Os valores usam o físico.';
    }
}
