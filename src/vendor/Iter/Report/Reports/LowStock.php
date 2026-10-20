<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/** E2: active SKUs with up to N salable, with what they sold in the last 30 days and about how long what is left lasts */
final class LowStock extends Report
{
    public function common(): array
    {
        return ['website_id'];
    }

    public function filters(): array
    {
        return [
            'max' => ['label' => 'Vendável até', 'type' => 'number', 'default' => 3],
            'category_id' => ['label' => 'Categoria', 'options' => self::categories()],
            'q' => ['label' => 'Busca', 'type' => 'search', 'placeholder' => 'SKU ou nome'],
            'show' => ['label' => 'Mostrar', 'options' => ['' => 'Esgotados e baixos', 'selling' => 'Só os que venderam em 30 dias', 'out' => 'Só esgotados']],
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
            'salable' => ['label' => 'Vendável', 'type' => 'qty'],
            'sold' => ['label' => 'Vendidos em 30 dias', 'type' => 'qty', 'sum' => true],
            'lasts' => ['label' => 'Dura cerca de (dias)', 'type' => 'int'],
            'situation' => ['label' => 'Situação', 'type' => 'text'],
            'out' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'out_selling' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'low' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'lost' => ['label' => '', 'type' => 'money', 'sum' => true, 'hidden' => true],
        ];
    }

    public function sort(): array
    {
        return ['salable', 'asc'];
    }

    public function lines(array $f): array
    {
        $bind = ['max' => $f['max']];
        $where = self::productWhere($f, $bind);
        $website = $f['website_id'] ? ' AND o.website_id = ' . (int) $f['website_id'] : '';
        $show = match ($f['show']) {
            'out' => ' AND x.salable <= 0',
            'selling' => ' AND x.sold > 0',
            default => '',
        };

        return ["SELECT x.*, CASE WHEN x.salable <= 0 THEN 'Esgotado' ELSE 'Baixo' END AS situation,
                CASE WHEN x.salable > 0 AND x.sold > 0 THEN FLOOR(x.salable / (x.sold / 30.0)) END AS lasts,
                CASE WHEN x.salable <= 0 THEN 1 ELSE 0 END AS out,
                CASE WHEN x.salable <= 0 AND x.sold > 0 THEN 1 ELSE 0 END AS out_selling,
                CASE WHEN x.salable > 0 THEN 1 ELSE 0 END AS low,
                CASE WHEN x.salable <= 0 THEN x.sold / 30.0 * x.price END AS lost
            FROM (SELECT p.sku, p.name, COALESCE(p.parent_id, p.product_id) AS edit_id, st.sources, st.physical, st.reserved, st.salable, pr.value AS price,
                    (SELECT COALESCE(SUM(it.quantity), 0) FROM sales_order_item it JOIN sales_order o ON o.order_id = it.order_id
                        WHERE it.product_id = p.product_id AND " . self::paid() . " AND o.created_at >= NOW() - INTERVAL '30 days'{$website}) AS sold
                FROM catalog_product p
                LEFT JOIN catalog_product pp ON pp.product_id = p.parent_id
                " . self::attribute('pr', 'price') . '
                LEFT JOIN LATERAL (' . self::stock($f) . ") st ON TRUE
                WHERE p.type <> 'configurable' AND p.is_active = 1 AND COALESCE(pp.is_active, 1) = 1 AND st.salable <= :max{$where}) x
            WHERE TRUE{$show}", $bind];
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        return [
            ['label' => 'Esgotados', 'value' => Format::show('int', $t['out']), 'note' => Format::show('int', $t['out_selling']) . ' com venda nos últimos 30 dias', 'tone' => 'bad'],
            ['label' => 'Estoque baixo', 'value' => Format::show('int', $t['low']), 'note' => 'vendável de 1 a ' . $f['max'], 'tone' => 'warn'],
            ['label' => 'Reservado', 'value' => Format::show('qty', $t['reserved']), 'note' => 'em pedidos ainda não enviados'],
            ['label' => 'Venda perdida por dia', 'value' => Format::show('money', $t['lost']), 'note' => 'esgotados × venda média diária (30 dias) × preço'],
        ];
    }

    public function note(array $t, array $f): string
    {
        return 'A situação de agora, só produtos ativos. Vendável = o físico marcado "em estoque" menos o que pedidos ainda não enviados seguram. Vendidos = unidades em pedidos pagos dos últimos 30 dias; "dura cerca de" divide o vendável pela venda média diária. Um SKU que nunca teve estoque lançado conta como esgotado.';
    }
}
