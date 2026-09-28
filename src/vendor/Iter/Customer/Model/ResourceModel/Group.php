<?php

declare(strict_types=1);

namespace Iter\Customer\Model\ResourceModel;

use Iter\Core\Model\ResourceModel\AbstractDb;

class Group extends AbstractDb
{
    protected function _construct(): void
    {
        $this->init('customer_group', 'customer_group_id');
    }

    /** @return array<int, array<string, mixed>> groups + customer_count */
    public function getList(?string $orderBy = null): array
    {
        $db = $this->getConnection();

        return $db->fetchAll($db->select()
            ->from(['g' => 'customer_group'])
            ->columns(['customer_count' => '(SELECT COUNT(*) FROM customer_entity c WHERE c.group_id = g.customer_group_id)'])
            ->order('g.customer_group_id'));
    }
}
