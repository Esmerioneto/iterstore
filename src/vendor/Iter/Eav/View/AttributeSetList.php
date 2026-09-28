<?php

declare(strict_types=1);

namespace Iter\Eav\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Eav\Model\AttributeSet;

class AttributeSetList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'eav/attribute-set-list';
    }

    public function getData(): array
    {
        return [
            'sets' => (new AttributeSet())->getResource()->getList(),
        ];
    }
}
