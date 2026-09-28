<?php

declare(strict_types=1);

namespace Iter\Customer\Controllers;

use Iter\Eav\Controllers\AbstractFormLayout;

/** "Organizar tela" on the customer form */
class Customerlayout extends AbstractFormLayout
{
    public const ADMIN_RESOURCE = 'Iter_Customer::customer_layout';
    protected const ENTITY_TYPE = 'customer';
}
