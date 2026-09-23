<?php

declare(strict_types=1);

namespace Iter\Store\Model\ResourceModel;

use Iter\Core\Model\ResourceModel\AbstractDb;
use PDO;

class Store extends AbstractDb
{
    protected function _construct(): void
    {
        $this->init('store', 'id');
    }

    /**
     * @return array<int, array<string, mixed>> stores with group/website names (null when ungrouped)
     */
    public function getList(?string $orderBy = null): array
    {
        return $this->getConnection()->query(
            'SELECT s.*, g.name AS group_name, w.name AS website_name
             FROM store s
             LEFT JOIN store_group g ON g.group_id = s.group_id
             LEFT JOIN store_website w ON w.website_id = g.website_id
             ORDER BY s.id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
