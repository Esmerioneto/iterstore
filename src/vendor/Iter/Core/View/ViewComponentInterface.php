<?php

declare(strict_types=1);

namespace Iter\Core\View;

interface ViewComponentInterface
{
    /**
     * Default template identifier, in "modulo/nome" form (ex: "user/welcome").
     * Overridden by the "template" property of the layout node, when present.
     */
    public function getTemplate(): string;

    /**
     * Default data made available to the template. Merged with (and
     * overridden by) the "data" property declared on the layout node.
     */
    public function getData(): array;
}
