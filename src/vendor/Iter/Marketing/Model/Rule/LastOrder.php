<?php

declare(strict_types=1);

namespace Iter\Marketing\Model\Rule;

use Iter\Core\Db\Connection;

/** shared by Lapsed and Overdue */
final class LastOrder
{
    /** no paid order after last_order:<id> */
    public static function stillLast(array $suggestion): bool
    {
        $orderId = (int) explode(':', $suggestion['reason_key'])[1];

        return !Connection::get()->adapter()->fetchOne("SELECT 1 FROM sales_order o JOIN sales_order l ON l.order_id = :id
            WHERE o.customer_id = l.customer_id AND o.order_id <> l.order_id AND o.created_at >= l.created_at
              AND o.status IN ('processing', 'shipped', 'complete')", ['id' => $orderId]);
    }
}
