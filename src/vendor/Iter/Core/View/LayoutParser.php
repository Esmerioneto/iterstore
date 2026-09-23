<?php

declare(strict_types=1);

namespace Iter\Core\View;

class LayoutParser
{
    /**
     * Converts an already-merged layout array into a ComponentTree.
     *
     * Rules:
     *  - string              -> component (component id)
     *  - object + component  -> configured component (component/template/before/after/data/children)
     *  - object w/ component "container" -> explicit container node (before/after/data apply, children from "children")
     *  - object w/o component -> container node, its own keys are children
     *  - false               -> removed, no node is created
     */
    public function parse(array $data): ComponentTree
    {
        $root = new ComponentNode(null);
        $this->buildChildren($root, $data);

        return new ComponentTree($root);
    }

    private function buildChildren(ComponentNode $parent, array $data): void
    {
        foreach ($data as $name => $value) {
            if ($value === false) {
                continue;
            }

            $name = (string) $name;
            $node = new ComponentNode($name);

            if (is_string($value)) {
                $node->setComponent($value);
            } elseif (is_array($value) && array_key_exists('component', $value)) {
                // "container" is a reserved component id: it stays a plain
                // container (like the implicit form) but still accepts
                // before/after/data/children, unlike the implicit form.
                if ($value['component'] !== 'container') {
                    $node->setComponent((string) $value['component']);
                }
                $node->setTemplate(isset($value['template']) ? (string) $value['template'] : null);
                $node->setBefore(isset($value['before']) ? (string) $value['before'] : null);
                $node->setAfter(isset($value['after']) ? (string) $value['after'] : null);
                $node->setData(isset($value['data']) && is_array($value['data']) ? $value['data'] : []);

                if (isset($value['children']) && is_array($value['children'])) {
                    $this->buildChildren($node, $value['children']);
                }
            } elseif (is_array($value)) {
                $this->buildChildren($node, $value);
            } else {
                continue;
            }

            $parent->addChild($node);
        }
    }
}
