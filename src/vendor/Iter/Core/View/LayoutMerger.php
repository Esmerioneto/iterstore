<?php

declare(strict_types=1);

namespace Iter\Core\View;

class LayoutMerger
{
    /**
     * Deep-merges layout arrays (already json_decoded) in module load order.
     * Objects are merged key by key; scalars, strings and `false` (removal)
     * from a later layout simply replace what came before.
     *
     * @param array[] $layouts
     */
    public function merge(array $layouts): array
    {
        $merged = [];
        foreach ($layouts as $layout) {
            $merged = $this->mergeValue($merged, $layout);
        }

        return $merged;
    }

    private function mergeValue($base, $override)
    {
        if ($this->isAssoc($base) && $this->isAssoc($override)) {
            foreach ($override as $key => $value) {
                $base[$key] = array_key_exists($key, $base)
                    ? $this->mergeValue($base[$key], $value)
                    : $value;
            }

            return $base;
        }

        return $override;
    }

    private function isAssoc($value): bool
    {
        return is_array($value) && ($value === [] || array_keys($value) !== range(0, count($value) - 1));
    }
}
