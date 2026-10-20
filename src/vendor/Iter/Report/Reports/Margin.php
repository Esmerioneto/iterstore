<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/**
 * F3: revenue − cost of the items, per product (the lines of V2) or per category (V3), with how much
 * of the revenue had no cost. Only for who may see the cost ("cost": true in reports.json).
 */
final class Margin extends Report
{
    public function filters(): array
    {
        return ['by' => ['label' => 'Agrupar', 'options' => ['product' => 'Por produto', 'category' => 'Por categoria']]] + $this->source(['by' => 'product'])->filters();
    }

    public function columns(array $f): array
    {
        return array_filter([
            'item' => ['label' => $f['by'] === 'category' ? 'Categoria' : 'Produto', 'type' => 'text'],
            'sku' => $f['by'] === 'category' ? null : ['label' => 'SKU', 'type' => 'text'],
            'revenue' => ['label' => 'Faturamento', 'type' => 'money', 'sum' => true],
            'net_cost' => ['label' => 'Faturamento com custo', 'type' => 'money', 'sum' => true, 'cost' => true],
            'cost' => ['label' => 'Custo', 'type' => 'money', 'sum' => true, 'cost' => true],
            'margin' => ['label' => 'Margem', 'type' => 'money', 'sum' => true, 'cost' => true],
            'margin_pct' => ['label' => 'Margem %', 'type' => 'pct', 'cost' => true],
            'no_cost' => ['label' => 'Faturamento sem custo', 'type' => 'money', 'sum' => true, 'cost' => true],
        ]);
    }

    public function sort(): array
    {
        return ['margin', 'desc'];
    }

    public function lines(array $f): array
    {
        [$sql, $bind] = $this->source($f)->lines($f);

        return ['SELECT ' . ($f['by'] === 'category' ? 'r.category AS item' : 'r.name AS item, r.sku') . ", r.revenue, r.net_cost, r.cost, r.margin, r.margin_pct,
                r.revenue - COALESCE(r.net_cost, 0) AS no_cost
            FROM ({$sql}) r", $bind];
    }

    /** by category, a product in two categories counts in both lines: the totals come from V3's own */
    public function totalsLines(array $f): ?array
    {
        $totals = $this->source($f)->totalsLines($f);
        if ($totals === null) {
            return null;
        }
        [$sql, $bind] = $totals;

        return ["SELECT r.revenue, r.net_cost, r.cost, r.margin, r.revenue - COALESCE(r.net_cost, 0) AS no_cost FROM ({$sql}) r", $bind];
    }

    public function totals(array $t, array $f): array
    {
        return self::margin($t);
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        $revenue = (float) $t['revenue'];

        return [
            ['label' => 'Faturamento', 'value' => Format::show('money', $t['revenue']), 'note' => 'itens de pedidos pagos, sem frete'],
            ['label' => 'Custo', 'value' => Format::show('money', $t['cost']), 'note' => 'dos itens com custo', 'tone' => 'warn'],
            ['label' => 'Margem bruta', 'value' => Format::show('money', $t['margin']), 'note' => $t['margin_pct'] === null ? 'sem itens com custo' : Format::show('pct', $t['margin_pct']) . ' dos itens com custo', 'tone' => 'ok'],
            ['label' => 'Sem custo', 'value' => Format::show('money', $t['no_cost']), 'note' => $revenue > 0 ? Format::show('pct', (float) $t['no_cost'] / $revenue) . ' do faturamento, fora da margem' : ''] + ((float) $t['no_cost'] > 0.004 ? ['tone' => 'bad'] : []),
        ];
    }

    public function note(array $t, array $f): string
    {
        return 'Pedidos pagos. Margem = faturamento dos itens (já com os descontos) − custo × quantidade, com o custo que o produto tinha no dia do pedido; o frete e a taxa do Mercado Pago ficam fora. Os itens sem custo ficam fora da margem e aparecem em "Faturamento sem custo".'
            . ($f['by'] === 'category' ? ' Um produto em duas categorias conta nas duas; o total não soma em dobro.' : '');
    }

    private function source(array $f): Report
    {
        return $f['by'] === 'category' ? new SalesByCategory($this->info) : new SalesByProduct($this->info);
    }
}
