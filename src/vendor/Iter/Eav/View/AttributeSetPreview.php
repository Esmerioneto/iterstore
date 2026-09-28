<?php

declare(strict_types=1);

namespace Iter\Eav\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Eav\Form\FormSchemaGenerator;
use Iter\Eav\Model\Attribute;
use Iter\Eav\Model\AttributeSet;

class AttributeSetPreview implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'eav/attribute-set-preview';
    }

    public function getData(): array
    {
        $set = (new AttributeSet())->load((int) ($_GET['id'] ?? 0));
        $entityType = array_column((new Attribute())->getResource()->getEntityTypes(), 'code', 'entity_type_id')[$set->getData('entity_type_id')];

        return [
            'set' => $set->getData(),
            'schema' => (new FormSchemaGenerator())->generate($entityType, $set->getData('code')),
            'values' => [],
        ];
    }
}
