<?php

declare(strict_types=1);

namespace Iter\Eav\View;

use Iter\Core\View\ViewComponentInterface;

/**
 * Renders the fields of an attribute set. Configured from the layout node:
 * {"component": "eav/form-fields", "data": {"entity_type": "product",
 *  "attribute_set": "default", "fields": {"cpf": {"required": false}}}}
 * Only fields are rendered, so the page's own <form> can wrap them. A
 * "values" array (code => value) fills them in; defaults otherwise.
 * "name_prefix": "attributes" posts them as attributes[code].
 * File fields (e.g. image) need the page's <form enctype="multipart/form-data">.
 */
class FormFields implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'eav/form-fields';
    }

    public function getData(): array
    {
        return ['attribute_set' => null, 'fields' => [], 'values' => [], 'name_prefix' => ''];
    }
}
