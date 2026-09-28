<?php

declare(strict_types=1);

namespace Iter\Inventory\Model\ResourceModel;

use Iter\Core\Db\Expr;
use Iter\Core\Model\ResourceModel\AbstractDb;

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
        $db = $this->getConnection();

        return $db->fetchAll($db->select()
            ->from(['s' => 'inventory_source'])
            ->columns(['products' => '(SELECT COUNT(*) FROM inventory_source_item i WHERE i.source_id = s.source_id AND i.quantity > 0)'])
            ->order('s.priority, s.source_id'));
    }

    public function codeExists(string $code): bool
    {
        $db = $this->getConnection();

        return (bool) $db->fetchOne($db->select()->from('inventory_source', [new Expr('1')])->where('code = ?', $code));
    }
}
