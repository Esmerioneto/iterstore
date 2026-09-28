<?php

declare(strict_types=1);

namespace Iter\Customer\Model\ResourceModel\Customer;

use Iter\Core\Model\ResourceModel\AbstractCollection;
use Iter\Customer\Model\Customer;
use Iter\Customer\Model\ResourceModel\Customer as CustomerResource;

/** @extends AbstractCollection<Customer> rows + group_name, website_name */
class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->init(Customer::class, CustomerResource::class);
    }

    protected function _initSelect(): void
    {
        $this->select
            ->join(['g' => 'customer_group'], 'g.customer_group_id = main_table.group_id', ['group_name' => 'name'])
            ->join(['w' => 'store_website'], 'w.website_id = main_table.website_id', ['website_name' => 'name']);
    }
}
