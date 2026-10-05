<?php

declare(strict_types=1);

namespace Iter\Sales\View;

use Iter\Admin\Auth;
use Iter\Core\View\ViewComponentInterface;
use Iter\Sales\Model\OrderService;

/** "Pedidos" under the customer's form (editing only) */
class CustomerOrders implements ViewComponentInterface
{
    private const LIMIT = 20;

    public function getTemplate(): string
    {
        return 'sales/customer-orders';
    }

    public function getData(): array
    {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0 || !Auth::isAllowed('Iter_Sales::order')) {
            return ['orders' => null];
        }
        $result = (new OrderService())->search(['customer_id' => (string) $id], 1, self::LIMIT);

        return ['orders' => $result['items'], 'total' => $result['total'], 'customer_id' => $id];
    }
}
