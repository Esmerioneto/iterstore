<?php

declare(strict_types=1);

namespace Iter\Inventory\Controllers;

use Iter\Core\Messages;
use Iter\Eav\Model\AttributeException;
use Iter\Inventory\Model\InventoryException;
use Iter\Inventory\Model\StockService;

/** the "Estoque" panel of the product form posts here */
class Stock
{
    public const ADMIN_RESOURCE = 'Iter_Inventory::stock';

    public function Save(): void
    {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $items = is_array($_POST['stock'] ?? null) ? $_POST['stock'] : [];

        try {
            (new StockService())->save($productId, $items);
            Messages::addSuccess('Estoque atualizado com sucesso.');
        } catch (AttributeException) { // PRODUCT_NOT_FOUND
            Messages::addError('Produto não encontrado.');
            header('Location: /admin/product/index');
            exit;
        } catch (InventoryException $e) {
            Messages::addError($e->getMessage());
            $_SESSION['iter_old_stock'] = $items;
        }

        header('Location: /admin/product/edit?id=' . $productId);
        exit;
    }
}
