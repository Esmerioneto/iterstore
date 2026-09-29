<?php

declare(strict_types=1);

namespace Iter\Inventory\View;

use Iter\Admin\Auth;
use Iter\Catalog\Model\Product;
use Iter\Core\View\ViewComponentInterface;
use Iter\Catalog\Model\VariantService;
use Iter\Inventory\Model\StockService;

/** "Estoque" panel under the product form; only when editing (the product must exist) */
class StockPanel implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'inventory/stock-panel';
    }

    public function getData(): array
    {
        $id = !empty($_GET['id']) ? (int) $_GET['id'] : 0;
        $product = (new Product())->load($id);
        if (!$id || !Auth::isAllowed('Iter_Inventory::stock') || !$product->getId()) {
            return ['product_id' => null];
        }
        if ($product->getData('type') === 'configurable') {
            return self::grid($id);
        }

        $old = $_SESSION['iter_old_stock'] ?? [];
        unset($_SESSION['iter_old_stock']);

        $service = new StockService();
        $items = $service->getItems($id);
        foreach ($items as &$item) {
            $item['quantity'] = (string) ($old[$item['code']]['quantity'] ?? $item['quantity']);
            $item['status'] = (int) ($old[$item['code']]['status'] ?? $item['status']);
        }
        unset($item);

        return [
            'product_id' => $id,
            'items' => $items,
            'salable' => str_replace('.', ',', $service->getSalable([$id])[$id]),
        ];
    }

    /**
     * "Estoque por grade": the quantities of one source, rows = the first grid attribute's
     * options, columns = the combinations of the others (a single column with one attribute)
     */
    private static function grid(int $id): array
    {
        $service = new StockService();
        $sources = array_column($service->getItems($id), 'name', 'code'); // the parent's websites = its variants'
        $source = isset($sources[$_GET['source'] ?? '']) ? $_GET['source'] : (string) key($sources);
        $variantService = new VariantService();
        $attributes = $variantService->getAttributes($id);
        $labels = array_map(static fn ($a) => array_column($a['options'], 'label', 'value'), $attributes);
        $cells = [];
        foreach ($variantService->getVariants($id) as $variant) {
            $names = array_map(static fn ($a, $l) => $l[$variant['options'][$a['code']]] ?? '', $attributes, $labels);
            // ponytail: one getItems() per variant; one query for all if grids get big
            $quantity = array_column($service->getItems($variant['product_id']), 'quantity', 'code')[$source] ?? '0';
            $cells[array_shift($names)][implode(' / ', $names) ?: 'Quantidade'] = $variant + ['quantity' => $quantity];
        }
        $columns = array_values(array_unique(array_merge(...array_map('array_keys', array_values($cells) ?: [[]]))));

        return [
            'product_id' => $id,
            'grid' => true,
            'sources' => $sources,
            'source' => $source,
            'rows' => $cells,
            'columns' => $columns,
            'salable' => str_replace('.', ',', $service->getSalable([$id])[$id]),
        ];
    }
}
