<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Iter\Core\View\LayoutMerger;
use Iter\Core\View\LayoutParser;
use Iter\Core\View\PositionResolver;

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

echo "\nTodos os testes passaram.\n";
