<?php

declare(strict_types=1);

namespace Iter\Customer\Model\ResourceModel\Customer;

use Iter\Eav\Model\ResourceModel\AbstractEntityCollection;
use Iter\Customer\Model\Customer;
use Iter\Customer\Model\ResourceModel\Customer as CustomerResource;

/** @extends AbstractEntityCollection<Customer> rows + group_name, website_name */
class Collection extends AbstractEntityCollection
{
    protected function _construct(): void
    {
        $this->init(Customer::class, CustomerResource::class);
    }

    protected function entityType(): string
    {
        return Customer::ENTITY_TYPE;
    }

    /** from customer_flat, so the registration fields (EAV) filter and sort like columns */
    protected function _initSelect(): void
    {
        $this->select = $this->resource->getConnection()->select()->from(['main_table' => 'customer_flat'])
            ->join(['g' => 'customer_group'], 'g.customer_group_id = main_table.group_id', ['group_name' => 'name'])
            ->join(['w' => 'store_website'], 'w.website_id = main_table.website_id', ['website_name' => 'name']);
    }
}
