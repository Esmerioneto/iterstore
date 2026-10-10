<?php

declare(strict_types=1);

namespace Iter\Eav\View;

use Iter\Admin\View\Pager;
use Iter\Core\View\ViewComponentInterface;
use Iter\Eav\Model\Attribute;
use Iter\Eav\Model\AttributeSet;

class AttributeSetList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'eav/attribute-set-list';
    }

    public function getData(): array
    {
        $entityType = trim((string) ($_GET['entity_type'] ?? ''));
        $sets = (new AttributeSet())->getResource()->getList();

        [$sets, $pager] = Pager::slice($entityType === '' ? $sets : array_values(array_filter($sets, static fn ($s) => $s['entity_type'] === $entityType)));

        return [
            'sets' => $sets,
            'pager' => $pager,
            'entity_type' => $entityType,
            'entity_types' => (new Attribute())->getResource()->getEntityTypes(),
        ];
    }
}
