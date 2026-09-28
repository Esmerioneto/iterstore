<?php

declare(strict_types=1);

namespace Iter\Store\Model\ResourceModel;

use Iter\Core\Model\AbstractModel;
use Iter\Core\Model\ResourceModel\AbstractDb;

class Website extends AbstractDb
{
    protected function _construct(): void
    {
        $this->init('store_website', 'website_id');
    }

    public function getList(?string $orderBy = null): array
    {
        return parent::getList($orderBy ?? 'sort_order, website_id');
    }

    /** @return array{websites: int, groups: int, stores: int, ungrouped: int} for the dashboard */
    public function getCounts(): array
    {
        $db = $this->getConnection();

        return array_map('intval', $db->fetchRow($db->select()->from('store_website', [])->columns([
            'websites' => 'COUNT(*)',
            'groups' => '(SELECT COUNT(*) FROM store_group)',
            'stores' => '(SELECT COUNT(*) FROM store)',
            'ungrouped' => '(SELECT COUNT(*) FROM store WHERE group_id IS NULL)',
        ])));
    }

    /**
     * Only one default website: marking one clears the flag on the others.
     */
    protected function afterSave(AbstractModel $object): void
    {
        if ((int) $object->getData('is_default') === 1) {
            $this->getConnection()->update('store_website', ['is_default' => 0], ['website_id <> ?' => $object->getId()]);
        }
    }
}
