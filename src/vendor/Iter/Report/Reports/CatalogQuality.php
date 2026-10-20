<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Admin\Auth;
use Iter\Catalog\Model\Product;
use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/**
 * C1: what to fix in the catalog. Active products: without image or description (the product, or
 * the product with variations), without weight, price or cost (what is sold: a simple product or a
 * variation). Inactive ones still holding stock.
 */
final class CatalogQuality extends Report
{
    /** problem => label; no_cost only for who may see the cost */
    public const PROBLEMS = [
        'no_image' => 'sem imagem', 'no_description' => 'sem descrição', 'no_weight' => 'sem peso',
        'no_price' => 'sem preço', 'no_cost' => 'sem custo', 'inactive_stock' => 'inativo com estoque',
    ];

    public function common(): array
    {
        return ['website_id'];
    }

    public function filters(): array
    {
        $problems = Auth::isAllowed(Product::COST_RESOURCE) ? self::PROBLEMS : array_diff_key(self::PROBLEMS, ['no_cost' => 1]);

        return [
            'problem' => ['label' => 'Problema', 'options' => ['' => 'Todos os problemas'] + array_map('ucfirst', $problems)],
            'q' => ['label' => 'Busca', 'type' => 'search', 'placeholder' => 'SKU ou nome'],
        ];
    }

    public function columns(array $f): array
    {
        $columns = [
            'sku' => ['label' => 'SKU', 'type' => 'text', 'link' => '/admin/product/edit?id={edit_id}'],
            'name' => ['label' => 'Produto', 'type' => 'text'],
            'kind' => ['label' => 'Tipo', 'type' => 'text'],
            'problems' => ['label' => 'O que falta', 'type' => 'text'],
            'lines' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
        ];
        foreach (self::PROBLEMS as $key => $label) {
            $columns[$key] = ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true];
        }

        return $columns;
    }

    public function sort(): array
    {
        return ['sku', 'asc'];
    }

    public function lines(array $f): array
    {
        $bind = [];
        $where = self::productWhere($f, $bind);
        $active = 'p.is_active = 1 AND COALESCE(pp.is_active, 1) = 1';
        $sold = "p.type <> 'configurable'";
        $image = "SELECT 1 FROM eav_value_varchar iv JOIN eav_attribute ia ON ia.attribute_id = iv.attribute_id AND ia.input_type = 'image'
            JOIN eav_entity_type it ON it.entity_type_id = ia.entity_type_id AND it.code = 'product' WHERE iv.value <> '' AND iv.entity_id";
        $flags = [
            // a variation shows its parent's images and description
            'no_image' => "{$active} AND p.parent_id IS NULL AND NOT EXISTS ({$image} = p.product_id)
                AND NOT EXISTS ({$image} IN (SELECT v.product_id FROM catalog_product v WHERE v.parent_id = p.product_id))",
            'no_description' => "{$active} AND p.parent_id IS NULL AND COALESCE(TRIM(d.value), '') = ''",
            'no_weight' => "{$active} AND {$sold} AND COALESCE(wt.value, 0) <= 0",
            'no_price' => "{$active} AND {$sold} AND pr.value IS NULL",
            'no_cost' => $f['cost'] ? "{$active} AND {$sold} AND c.value IS NULL" : 'FALSE',
            'inactive_stock' => "NOT ({$active}) AND {$sold} AND st.physical > 0",
        ];
        $problem = isset($flags[$f['problem']]) ? " WHERE x.{$f['problem']} = 1" : ' WHERE x.' . implode(' + x.', array_keys($flags)) . ' > 0';

        return ["SELECT x.*, 1 AS lines, CONCAT_WS(', '" . implode('', array_map(static fn ($k, $l) => ", CASE WHEN x.{$k} = 1 THEN '{$l}' END", array_keys(self::PROBLEMS), self::PROBLEMS)) . ") AS problems
            FROM (SELECT p.sku, p.name, COALESCE(p.parent_id, p.product_id) AS edit_id,
                    CASE WHEN p.parent_id IS NOT NULL THEN 'Variação' WHEN p.type = 'configurable' THEN 'Com variações' ELSE 'Simples' END AS kind, "
                    . implode(', ', array_map(static fn ($k, $sql) => "CASE WHEN {$sql} THEN 1 ELSE 0 END AS {$k}", array_keys($flags), $flags)) . '
                FROM catalog_product p
                LEFT JOIN catalog_product pp ON pp.product_id = p.parent_id
                ' . self::attribute('pr', 'price') . '
                ' . self::attribute('c', 'cost') . '
                ' . self::attribute('wt', 'weight') . '
                ' . self::attribute('d', 'description', 'text') . '
                LEFT JOIN LATERAL (' . self::stock($f) . ") st ON TRUE
                WHERE TRUE{$where}) x{$problem}", $bind];
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        $tiles = [];
        foreach (self::PROBLEMS as $key => $label) {
            if ($key !== 'no_cost' || $f['cost']) {
                $tiles[] = ['label' => ucfirst($label), 'value' => Format::show('int', $t[$key])] + ((int) $t[$key] ? ['tone' => 'warn'] : []);
            }
        }

        return $tiles;
    }

    public function note(array $t, array $f): string
    {
        return 'A situação de agora. Imagem e descrição são do produto (uma variação usa as do produto com variações); peso, preço' . ($f['cost'] ? ', custo' : '') . ' são de cada item vendido (produto simples ou variação). Sem peso, o frete calculado sai errado. O SKU abre o produto.';
    }
}
