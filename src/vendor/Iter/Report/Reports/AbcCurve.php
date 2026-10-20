<?php

declare(strict_types=1);

namespace Iter\Report\Reports;

use Iter\Report\Model\Format;
use Iter\Report\Model\Report;

/**
 * E5: the products of V2 (same filters and numbers) ranked by revenue with the accumulated share:
 * A while the products before it make less than 80% of the revenue, B up to 95%, C the rest.
 */
final class AbcCurve extends Report
{
    public const CLASSES = ['A' => 0.80, 'B' => 0.95];

    public function preset(): string
    {
        return '90d';
    }

    public function filters(): array
    {
        return (new SalesByProduct($this->info))->filters() + ['class' => ['label' => 'Classe', 'options' => ['' => 'Todas as classes', 'A' => 'Classe A', 'B' => 'Classe B', 'C' => 'Classe C']]];
    }

    public function columns(array $f): array
    {
        return [
            'rank' => ['label' => 'Posição', 'type' => 'int'],
            'sku' => ['label' => 'SKU', 'type' => 'text'],
            'name' => ['label' => 'Produto', 'type' => 'text'],
            'qty' => ['label' => 'Qtd.', 'type' => 'qty', 'sum' => true],
            'revenue' => ['label' => 'Faturamento', 'type' => 'money', 'sum' => true],
            'margin' => ['label' => 'Margem', 'type' => 'money', 'sum' => true, 'cost' => true],
            'share' => ['label' => '% do total', 'type' => 'pct', 'sum' => true],
            'cumulative' => ['label' => '% acumulado', 'type' => 'pct', 'sort' => 'rank'],
            'class' => ['label' => 'Classe', 'type' => 'text', 'sort' => 'rank'],
            'a' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'b' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'c' => ['label' => '', 'type' => 'int', 'sum' => true, 'hidden' => true],
            'a_revenue' => ['label' => '', 'type' => 'money', 'sum' => true, 'hidden' => true],
            'b_revenue' => ['label' => '', 'type' => 'money', 'sum' => true, 'hidden' => true],
        ];
    }

    public function sort(): array
    {
        return ['revenue', 'desc'];
    }

    public function lines(array $f): array
    {
        [$sql, $bind] = (new SalesByProduct($this->info))->lines($f);
        $class = '';
        if ($f['class'] !== '') {
            $class = ' WHERE r.class = :class';
            $bind['class'] = $f['class'];
        }

        return ["SELECT r.*, CASE r.class WHEN 'A' THEN 1 ELSE 0 END AS a, CASE r.class WHEN 'B' THEN 1 ELSE 0 END AS b, CASE r.class WHEN 'C' THEN 1 ELSE 0 END AS c,
                CASE r.class WHEN 'A' THEN r.revenue ELSE 0 END AS a_revenue, CASE r.class WHEN 'B' THEN r.revenue ELSE 0 END AS b_revenue
            FROM (SELECT w.*, CASE WHEN w.cumulative - w.share < " . self::CLASSES['A'] . " THEN 'A' WHEN w.cumulative - w.share < " . self::CLASSES['B'] . " THEN 'B' ELSE 'C' END AS class
                FROM (SELECT v.sku, v.name, v.qty, v.revenue, v.margin, v.share,
                        ROW_NUMBER() OVER (ORDER BY v.revenue DESC, v.sku) AS rank,
                        SUM(v.share) OVER (ORDER BY v.revenue DESC, v.sku) AS cumulative
                    FROM ({$sql}) v) w) r{$class}", $bind];
    }

    public function tiles(array $t, array $f, callable $totalsOf): array
    {
        $revenue = (float) $t['revenue'];
        $part = static fn ($v) => $revenue > 0 ? Format::show('pct', (float) $v / $revenue) . ' do faturamento' : '';

        return [
            ['label' => 'Classe A', 'value' => Format::show('int', $t['a']) . ' produto(s)', 'note' => $part($t['a_revenue']), 'tone' => 'ok'],
            ['label' => 'Classe B', 'value' => Format::show('int', $t['b']) . ' produto(s)', 'note' => $part($t['b_revenue'])],
            ['label' => 'Classe C', 'value' => Format::show('int', $t['c']) . ' produto(s)', 'note' => $part($revenue - (float) $t['a_revenue'] - (float) $t['b_revenue']), 'tone' => 'warn'],
            ['label' => 'Faturamento', 'value' => Format::show('money', $t['revenue']), 'note' => 'itens de pedidos pagos, sem frete'],
        ];
    }

    public function note(array $t, array $f): string
    {
        return 'Pedidos pagos, os mesmos números de Vendas por produto. A: os produtos que somam os primeiros 80% do faturamento; B: os próximos 15%; C: os 5% finais. Com o filtro de classe, o % acumulado continua contando todos os produtos.';
    }
}
