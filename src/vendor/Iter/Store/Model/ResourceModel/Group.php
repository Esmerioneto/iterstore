<?php

declare(strict_types=1);

namespace Iter\Store\Model\ResourceModel;

use Iter\Core\Model\ResourceModel\AbstractDb;

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
        $db = $this->getConnection();

        return $db->fetchAll($db->select()
            ->from(['g' => 'store_group'])
            ->join(['w' => 'store_website'], 'w.website_id = g.website_id', ['website_name' => 'name'])
            ->order('w.sort_order, w.website_id, g.group_id'));
    }
}
