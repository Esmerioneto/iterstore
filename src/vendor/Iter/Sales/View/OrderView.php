<?php

declare(strict_types=1);

namespace Iter\Sales\View;

use Iter\Admin\Auth;
use Iter\Core\View\ViewComponentInterface;
use Iter\Inventory\Model\StockService;
use Iter\Sales\Model\OrderService;

class OrderView implements ViewComponentInterface
{
    /** action => the permission it needs */
    private const RESOURCES = ['confirm' => 'Iter_Sales::order_payment', 'ship' => 'Iter_Sales::order_ship', 'complete' => 'Iter_Sales::order_ship', 'cancel' => 'Iter_Sales::order_cancel'];

    public function getTemplate(): string
    {
        return 'sales/order-view';
    }

    public function getData(): array
    {
        $order = (new OrderService())->get((int) ($_GET['id'] ?? 0));
        $actions = array_values(array_filter(OrderService::actions($order['status']), static fn ($a) => Auth::isAllowed(self::RESOURCES[$a])));
        $quantities = [];
        foreach ($order['items'] as $item) {
            if ($item['product_id'] !== null) {
                $quantities[(int) $item['product_id']] = ($quantities[(int) $item['product_id']] ?? 0) + $item['quantity'];
            }
        }

        return [
            'order' => $order,
            'actions' => $actions,
            'sources' => in_array('ship', $actions, true) ? (new StockService())->shipSources((int) $order['website_id'], $quantities) : [],
        ];
    }
}
