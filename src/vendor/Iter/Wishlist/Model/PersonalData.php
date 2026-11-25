<?php

declare(strict_types=1);

namespace Iter\Wishlist\Model;

use Iter\Core\Db\Connection;
use Iter\Lgpd\Model\PersonalDataInterface;

/** LGPD for the favourites (Iter_Lgpd calls it, see etc/lgpd.json): listed in the copy, deleted on erase */
final class PersonalData implements PersonalDataInterface
{
    public function export(?int $customerId, string $email): array
    {
        if ($customerId === null) {
            return [];
        }

        return Connection::get()->adapter()->fetchCol("SELECT p.name || ' (' || p.sku || ') — desde ' || to_char(w.created_at, 'DD/MM/YYYY')
            FROM customer_wishlist_item w JOIN catalog_product p ON p.product_id = w.product_id WHERE w.customer_id = :c ORDER BY w.created_at", ['c' => $customerId]);
    }

    public function blockers(int $customerId): array
    {
        return [];
    }

    public function erase(int $customerId, string $email): void
    {
        Connection::get()->adapter()->delete('customer_wishlist_item', ['customer_id = ?' => $customerId]);
    }
}
