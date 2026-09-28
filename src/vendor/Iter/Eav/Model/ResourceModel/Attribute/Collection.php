<?php

declare(strict_types=1);

namespace Iter\Eav\Model\ResourceModel\Attribute;

use Iter\Core\Model\ResourceModel\AbstractCollection;
use Iter\Eav\Model\Attribute;
use Iter\Eav\Model\ResourceModel\Attribute as AttributeResource;

/** @extends AbstractCollection<Attribute> rows + entity_type (code), entity_type_label */
class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->init(Attribute::class, AttributeResource::class);
    }

    protected function _initSelect(): void
    {
        $this->select->join(['et' => 'eav_entity_type'], 'et.entity_type_id = main_table.entity_type_id', [
            'entity_type' => 'code',
            'entity_type_label' => 'label',
        ]);
    }
}
