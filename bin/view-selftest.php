<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Iter\Admin\View\GridColumnSource;
use Iter\Admin\View\GridColumns;
use Iter\Core\View\LayoutMerger;
use Iter\Core\View\LayoutParser;
use Iter\Core\View\PositionResolver;

/** a column source for test 9: the row's name in capitals */
final class SelftestGridSource implements GridColumnSource
{
    public function values(array $rows): array
    {
        return array_map('strtoupper', array_column($rows, 'name', 'id'));
    }
}

function assertSame($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FALHOU: {$label}\nesperado: " . var_export($expected, true) . "\nobtido:   " . var_export($actual, true) . "\n");
        exit(1);
    }
    echo "OK: {$label}\n";
}

// 1) Merge entre "módulos": um define content.product/price, outro adiciona discount
$moduleA = [
    'content' => [
        'product' => 'catalog/product',
        'price' => 'catalog/price',
    ],
];
$moduleB = [
    'content' => [
        'discount' => [
            'component' => 'catalog/discount',
            'after' => 'price',
        ],
    ],
];
$merged = (new LayoutMerger())->merge([$moduleA, $moduleB]);
assertSame(['product', 'price', 'discount'], array_keys($merged['content']), 'merge combina layouts de dois modulos');

// 2) before/after simples
$tree = (new LayoutParser())->parse($merged);
(new PositionResolver())->resolve($tree);
$content = $tree->getRoot()->getChild('content');
assertSame(['product', 'price', 'discount'], array_keys($content->getChildren()), 'after posiciona discount depois de price');

// 3) before, @first, @last e remoção
$layout = [
    'content' => [
        'a' => 'vendor/a',
        'b' => [
            'component' => 'vendor/b',
            'before' => 'a',
        ],
        'c' => [
            'component' => 'vendor/c',
            'after' => '@last',
        ],
        'banner' => [
            'component' => 'vendor/banner',
            'before' => '@first',
        ],
        'removed' => false,
    ],
];
$tree = (new LayoutParser())->parse($layout);
(new PositionResolver())->resolve($tree);
$content = $tree->getRoot()->getChild('content');
assertSame(['banner', 'b', 'a', 'c'], array_keys($content->getChildren()), 'before/@first/@last ordenam corretamente');
assertSame(null, $content->getChild('removed'), 'false remove o componente da arvore');

// 4) referencia desconhecida em after: nao quebra, cai no fim do container
// (b vem antes de a no JSON, mas sua referencia "after: unknown" nao existe)
$layout = [
    'content' => [
        'b' => [
            'component' => 'vendor/b',
            'after' => 'unknown',
        ],
        'a' => 'vendor/a',
    ],
];
$tree = (new LayoutParser())->parse($layout);
(new PositionResolver())->resolve($tree);
assertSame(['a', 'b'], array_keys($tree->getRoot()->getChild('content')->getChildren()), 'referencia inexistente cai no final do container');

// 5) dependencia circular deve lancar excecao
$layout = [
    'content' => [
        'a' => [
            'component' => 'vendor/a',
            'after' => 'b',
        ],
        'b' => [
            'component' => 'vendor/b',
            'after' => 'a',
        ],
    ],
];
$tree = (new LayoutParser())->parse($layout);
try {
    (new PositionResolver())->resolve($tree);
    fwrite(STDERR, "FALHOU: ciclo deveria lancar RuntimeException\n");
    exit(1);
} catch (\RuntimeException $e) {
    echo "OK: ciclo em before/after lanca RuntimeException ({$e->getMessage()})\n";
}

// 6) container implicito (objeto sem "component") e hierarquia aninhada
$layout = [
    'content' => [
        'product-info' => [
            'title' => 'catalog/product-title',
            'price' => 'catalog/price',
        ],
    ],
];
$tree = (new LayoutParser())->parse($layout);
$productInfo = $tree->getRoot()->getChild('content')->getChild('product-info');
assertSame(null, $productInfo->getComponent(), 'objeto sem "component" vira container');
assertSame(['title', 'price'], array_keys($productInfo->getChildren()), 'container implicito preserva filhos');

// 7) container explicito (component: "container") com children e before/after
$layout = [
    'content' => [
        'product' => 'catalog/product',
        'product-info' => [
            'component' => 'container',
            'after' => 'product',
            'children' => [
                'title' => 'catalog/product-title',
                'price' => 'catalog/price',
            ],
        ],
    ],
];
$tree = (new LayoutParser())->parse($layout);
(new PositionResolver())->resolve($tree);
$content = $tree->getRoot()->getChild('content');
$productInfo = $content->getChild('product-info');
assertSame(['product', 'product-info'], array_keys($content->getChildren()), 'container explicito respeita after');
assertSame(null, $productInfo->getComponent(), 'component:"container" nao vira um componente resolvivel');
assertSame(['title', 'price'], array_keys($productInfo->getChildren()), 'container explicito preserva filhos em "children"');

// 8) modo estrito: referencia desconhecida deve lancar excecao em vez de cair no fim
$layout = [
    'content' => [
        'b' => [
            'component' => 'vendor/b',
            'after' => 'unknown',
        ],
        'a' => 'vendor/a',
    ],
];
$tree = (new LayoutParser())->parse($layout);
try {
    (new PositionResolver(strict: true))->resolve($tree);
    fwrite(STDERR, "FALHOU: modo estrito deveria lancar RuntimeException para referencia desconhecida\n");
    exit(1);
} catch (\RuntimeException $e) {
    echo "OK: modo estrito lanca RuntimeException para referencia desconhecida ({$e->getMessage()})\n";
}

// 9) colunas que outro módulo acrescenta a uma grid (GridColumns): na posição de "after", "after"
// desconhecido vai depois da última, outra grid não entra, o valor é escapado, id sem valor fica vazio
$rows = [['id' => 1, 'name' => 'ana <b>'], ['id' => 2, 'name' => 'bia']];
$columns = new GridColumns('test/grid', $rows, ['id', 'name', 'status'], [
    ['grid' => 'test/grid', 'code' => 'upper', 'label' => 'Maiúsculo', 'after' => 'id', 'class' => SelftestGridSource::class],
    ['grid' => 'test/grid', 'code' => 'lost', 'label' => 'Sem lugar', 'after' => 'nope', 'class' => SelftestGridSource::class],
    ['grid' => 'other/grid', 'code' => 'other', 'label' => 'Outra', 'after' => 'id', 'class' => SelftestGridSource::class],
]);
assertSame([2, '<th class="grid-column-upper">Maiúsculo</th>', '', '<th class="grid-column-lost">Sem lugar</th>'],
    [$columns->count(), $columns->th('id'), $columns->th('name'), $columns->th('status')], 'grid: colunas extras no lugar de "after", desconhecido vai para o fim, outra grid fica de fora');
assertSame(['<td class="grid-column-upper">ANA &lt;B&gt;</td>', '<td class="grid-column-upper"></td>'],
    [$columns->td('id', 1), (new GridColumns('test/grid', [$rows[1]], ['id'], [['grid' => 'test/grid', 'code' => 'upper', 'label' => 'x', 'after' => 'id', 'class' => SelftestGridSource::class]]))->td('id', 1)],
    'grid: valor escapado; linha sem valor fica vazia');
try {
    new GridColumns('test/grid', $rows, ['id'], [['grid' => 'test/grid', 'code' => 'bad', 'label' => 'x', 'after' => 'id', 'class' => \stdClass::class]]);
    fwrite(STDERR, "FALHOU: classe que não é GridColumnSource deveria lançar RuntimeException\n");
    exit(1);
} catch (\RuntimeException $e) {
    echo "OK: grid: classe que não é GridColumnSource é recusada ({$e->getMessage()})\n";
}

// 10) toda grid do admin, de qualquer módulo (Iter ou app/code), aceita colunas de outros módulos: uma grid é
// um template com colunas ordenáveis (o padrão das listas) ou uma das que não ordenam, listadas aqui
$unsorted = ['Catalog/view/frontend/templates/category-list.php', 'Admin/view/frontend/templates/apikey-list.php'];
// ordenam, mas não são grids de registros: as colunas do relatório vêm da classe de cada relatório
$notGrids = ['Report/view/frontend/templates/view.php'];
$listed = static fn (string $relative, array $list): bool => (bool) array_filter($list, static fn ($u) => str_ends_with($relative, $u));
$missing = [];
$grids = 0;
foreach (glob(__DIR__ . '/../src/{vendor,app/code}/*/*/view/frontend/templates/*.php', GLOB_BRACE) as $file) {
    $html = (string) file_get_contents($file);
    $relative = substr($file, strpos($file, '/src/') + 5);
    if ($listed($relative, $notGrids) || (!preg_match('/Sort::th\(|\$sortHeader\(/', $html) && !$listed($relative, $unsorted))) {
        continue;
    }
    $grids++;
    $needs = ['$gridColumns->th(', '$gridColumns->td('];
    if (str_contains($html, 'admin-empty') && str_contains($html, 'colspan=')) {
        $needs[] = '$gridColumns->count()';
    }
    foreach ($needs as $need) {
        if (!str_contains($html, $need)) {
            $missing[] = "{$relative}: falta {$need}";
        }
    }
}
assertSame([], $missing, "grid: as {$grids} grids do admin chamam \$gridColumns->th()/td() (e count() no colspan da linha vazia)");

echo "\nTodos os testes passaram.\n";
