<?php

declare(strict_types=1);

namespace Iter\Core\View;

class ComponentTree
{
    private ComponentNode $root;

    public function __construct(ComponentNode $root)
    {
        $this->root = $root;
    }

    public function getRoot(): ComponentNode
    {
        return $this->root;
    }
}
