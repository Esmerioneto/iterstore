<?php

declare(strict_types=1);

namespace Iter\Catalog\Controllers;

use Iter\Eav\Controllers\AbstractFormLayout;

/** "Organizar tela" on the product form */
class Productlayout extends AbstractFormLayout
{
    public const ADMIN_RESOURCE = 'Iter_Catalog::product_layout';
    protected const ENTITY_TYPE = 'product';
}
