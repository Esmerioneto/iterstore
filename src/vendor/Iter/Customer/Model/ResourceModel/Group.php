<?php

declare(strict_types=1);

namespace Iter\Customer\Model\ResourceModel;

use Iter\Core\Model\ResourceModel\AbstractDb;
use PDO;

class Group extends AbstractDb
{
    protected function _construct(): void
    {
        $this->init('customer_group', 'customer_group_id');
    }

    /** @return array<int, array<string, mixed>> groups + customer_count */
    public function getList(?string $orderBy = null): array
    {
        return $this->getConnection()->query(
            'SELECT g.*, (SELECT COUNT(*) FROM customer_entity c WHERE c.group_id = g.customer_group_id) AS customer_count
             FROM customer_group g
             ORDER BY g.customer_group_id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
