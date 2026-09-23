<?php

declare(strict_types=1);

namespace Iter\Store\Model\ResourceModel;

use Iter\Core\Model\ResourceModel\AbstractDb;
use PDO;

class Group extends AbstractDb
{
    protected function _construct(): void
    {
        $this->init('store_group', 'group_id');
    }

    /**
     * @return array<int, array<string, mixed>> groups with their website name
     */
    public function getList(?string $orderBy = null): array
    {
        return $this->getConnection()->query(
            'SELECT g.*, w.name AS website_name
             FROM store_group g
             JOIN store_website w ON w.website_id = g.website_id
             ORDER BY w.sort_order, w.website_id, g.group_id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
