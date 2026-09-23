<?php

declare(strict_types=1);

namespace Iter\Core\View;

class LayoutRenderer
{
    private ComponentResolver $resolver;

    public function __construct(?ComponentResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new ComponentResolver();
    }

    public function render(ComponentTree $tree): string
    {
        return $this->renderNode($tree->getRoot());
    }

    private function renderNode(ComponentNode $node): string
    {
        $childrenHtml = [];
        foreach ($node->getChildren() as $child) {
            $childrenHtml[$child->getName()] = $this->renderNode($child);
        }

        if ($node->getComponent() === null) {
            // pure container/root: no template of its own, just its children in order
            return implode('', $childrenHtml);
        }

        $component = $this->resolver->resolve($node->getComponent());
        $templateId = $node->getTemplate() ?? $component->getTemplate();
        $templatePath = $this->resolver->resolveTemplate($templateId);
        $data = array_merge($component->getData(), $node->getData());

        return $this->renderTemplate($templatePath, $data, $childrenHtml);
    }

    /**
     * @param array<string, string> $children rendered HTML keyed by child name
     */
    private function renderTemplate(string $templatePath, array $data, array $children): string
    {
        $render = static function (string $__template, array $data, array $children): string {
            extract($data, EXTR_SKIP);
            ob_start();
            require $__template;

            return (string) ob_get_clean();
        };

        return $render($templatePath, $data, $children);
    }
}
