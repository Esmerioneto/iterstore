<?php

declare(strict_types=1);

namespace Iter\Inventory\View;

use Iter\Admin\Auth;
use Iter\Catalog\Model\Product;
use Iter\Core\View\ViewComponentInterface;
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
        if (!$id || !Auth::isAllowed('Iter_Inventory::stock') || !(new Product())->load($id)->getId()) {
            return ['product_id' => null];
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
}
