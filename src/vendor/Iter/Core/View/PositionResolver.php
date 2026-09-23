<?php

declare(strict_types=1);

namespace Iter\Core\View;

use RuntimeException;

class PositionResolver
{
    private bool $strict;

    /**
     * @param bool $strict When true, an unknown before/after reference throws
     *                     instead of falling back to the end of the container.
     */
    public function __construct(bool $strict = false)
    {
        $this->strict = $strict;
    }

    public function resolve(ComponentTree $tree): void
    {
        $this->resolveNode($tree->getRoot());
    }

    private function resolveNode(ComponentNode $node): void
    {
        $node->setChildren($this->reorder($node->getChildren()));
        foreach ($node->getChildren() as $child) {
            $this->resolveNode($child);
        }
    }

    /**
     * Reorders a set of siblings using before/after/@first/@last as ordering
     * constraints, resolved with a stable topological sort (Kahn's algorithm).
     * An unknown before/after reference falls back to the end of the container
     * (spec section 17). A constraint cycle surfaces as a leftover, unsorted
     * node, which is reported as a configuration error.
     *
     * @param array<string, ComponentNode> $children
     * @return array<string, ComponentNode>
     */
    private function reorder(array $children): array
    {
        if (count($children) <= 1) {
            return $children;
        }

        $names = array_keys($children);
        $indexOf = array_flip($names);

        $edges = array_fill_keys($names, []);
        $inDegree = array_fill_keys($names, 0);

        $addEdge = function (string $before, string $after) use (&$edges, &$inDegree): void {
            $edges[$before][] = $after;
            $inDegree[$after]++;
        };

        $pushToEnd = function (string $name) use ($names, $addEdge): void {
            foreach ($names as $other) {
                if ($other !== $name) {
                    $addEdge($other, $name);
                }
            }
        };

        $unknownReference = function (string $name, string $property, string $reference) use ($pushToEnd): void {
            if ($this->strict) {
                throw new RuntimeException(
                    "Referencia '{$property}' desconhecida em '{$name}': '{$reference}' nao existe neste container"
                );
            }

            $pushToEnd($name);
        };

        foreach ($names as $name) {
            $node = $children[$name];

            $after = $node->getAfter();
            if ($after === '@last') {
                $pushToEnd($name);
            } elseif ($after !== null) {
                isset($children[$after]) ? $addEdge($after, $name) : $unknownReference($name, 'after', $after);
            }

            $before = $node->getBefore();
            if ($before === '@first') {
                foreach ($names as $other) {
                    if ($other !== $name) {
                        $addEdge($name, $other);
                    }
                }
            } elseif ($before !== null) {
                isset($children[$before]) ? $addEdge($name, $before) : $unknownReference($name, 'before', $before);
            }
        }

        $sorted = $this->topologicalSort($names, $edges, $inDegree, $indexOf);

        $result = [];
        foreach ($sorted as $name) {
            $result[$name] = $children[$name];
        }

        return $result;
    }

    /**
     * @param string[] $names
     * @param array<string, string[]> $edges
     * @param array<string, int> $inDegree
     * @param array<string, int> $indexOf
     * @return string[]
     */
    private function topologicalSort(array $names, array $edges, array $inDegree, array $indexOf): array
    {
        $ready = array_values(array_filter($names, static fn (string $n) => $inDegree[$n] === 0));

        $sorted = [];
        while (!empty($ready)) {
            usort($ready, static fn (string $a, string $b) => $indexOf[$a] <=> $indexOf[$b]);
            $current = array_shift($ready);
            $sorted[] = $current;

            foreach ($edges[$current] as $next) {
                $inDegree[$next]--;
                if ($inDegree[$next] === 0) {
                    $ready[] = $next;
                }
            }
        }

        if (count($sorted) !== count($names)) {
            $remaining = implode(', ', array_diff($names, $sorted));
            throw new RuntimeException("Dependencia circular detectada em before/after entre: {$remaining}");
        }

        return $sorted;
    }
}
