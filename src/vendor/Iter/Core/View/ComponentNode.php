<?php

declare(strict_types=1);

namespace Iter\Core\View;

class ComponentNode
{
    private ?string $name;
    private ?string $component = null;
    private ?string $template = null;
    private array $data = [];
    private ?string $before = null;
    private ?string $after = null;

    /** @var array<string, ComponentNode> keyed by name, preserves insertion order */
    private array $children = [];

    public function __construct(?string $name)
    {
        $this->name = $name;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setComponent(?string $component): void
    {
        $this->component = $component;
    }

    public function getComponent(): ?string
    {
        return $this->component;
    }

    public function setTemplate(?string $template): void
    {
        $this->template = $template;
    }

    public function getTemplate(): ?string
    {
        return $this->template;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setBefore(?string $before): void
    {
        $this->before = $before;
    }

    public function getBefore(): ?string
    {
        return $this->before;
    }

    public function setAfter(?string $after): void
    {
        $this->after = $after;
    }

    public function getAfter(): ?string
    {
        return $this->after;
    }

    public function addChild(ComponentNode $child): void
    {
        $this->children[$child->getName()] = $child;
    }

    public function getChild(string $name): ?ComponentNode
    {
        return $this->children[$name] ?? null;
    }

    /**
     * @return array<string, ComponentNode>
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * @param array<string, ComponentNode> $children
     */
    public function setChildren(array $children): void
    {
        $this->children = $children;
    }
}
