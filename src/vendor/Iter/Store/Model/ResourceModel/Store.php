<?php

declare(strict_types=1);

namespace Iter\Store\Model\ResourceModel;

use Iter\Core\Model\ResourceModel\AbstractDb;

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
        $db = $this->getConnection();

        return $db->fetchAll($db->select()
            ->from(['s' => 'store'])
            ->joinLeft(['g' => 'store_group'], 'g.group_id = s.group_id', ['group_name' => 'name'])
            ->joinLeft(['w' => 'store_website'], 'w.website_id = g.website_id', ['website_name' => 'name'])
            ->order('s.id'));
    }
}
