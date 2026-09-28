<?php

declare(strict_types=1);

namespace Iter\Inventory\Model\ResourceModel;

use Iter\Core\Model\ResourceModel\AbstractDb;
use PDO;

class Source extends AbstractDb
{
    protected function _construct(): void
    {
        $this->init('inventory_source', 'source_id');
    }

    /** lower priority first (the order stock is picked from, once there are orders) */
    public function getList(?string $orderBy = null): array
    {
        return parent::getList($orderBy ?? 'priority, source_id');
    }

    /** @return array<int, array<string, mixed>> sources + products (count of products with quantity > 0) */
    public function getListWithCounts(): array
    {
        return $this->getConnection()->query(
            'SELECT s.*, (SELECT COUNT(*) FROM inventory_source_item i WHERE i.source_id = s.source_id AND i.quantity > 0) AS products
             FROM inventory_source s ORDER BY s.priority, s.source_id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function codeExists(string $code): bool
    {
        $stmt = $this->getConnection()->prepare('SELECT 1 FROM inventory_source WHERE code = :code');
        $stmt->execute(['code' => $code]);

        return (bool) $stmt->fetchColumn();
    }
}
