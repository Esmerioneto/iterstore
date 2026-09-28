<?php

declare(strict_types=1);

namespace Iter\Customer\Model;

use Iter\Core\Model\AbstractModel;
use Iter\Customer\Model\ResourceModel\Customer as CustomerResource;

/**
 * @method CustomerResource getResource()
 */
class Customer extends AbstractModel
{
    /** entity type code of customers in eav_entity_type */
    public const ENTITY_TYPE = 'customer';

    public const TYPES = ['pf' => 'Pessoa física', 'pj' => 'Pessoa jurídica'];

    protected string $_idFieldName = 'customer_id';

    protected function _construct(): void
    {
        $this->_resource = new CustomerResource();
    }
}
