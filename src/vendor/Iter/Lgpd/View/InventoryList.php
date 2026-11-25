<?php

declare(strict_types=1);

namespace Iter\Lgpd\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Lgpd\Model\Inventory;

/**
 * LGPD → Inventário. Not a grid of records (no sorting, paging or extra columns): a short read-only list built
 * from the modules' etc/lgpd.json, the same on every request.
 */
final class InventoryList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'lgpd/inventory';
    }

    public function getData(): array
    {
        $operators = Inventory::operators();

        return [
            'activities' => Inventory::activities(),
            'operators' => $operators,
            'bases' => Inventory::BASES,
            'policyText' => self::policyText($operators),
        ];
    }

    /** the "who we share with" part of the privacy policy, to paste into it */
    public static function policyText(array $operators): string
    {
        $lines = ['Compartilhamos dados pessoais apenas com os parceiros abaixo, na medida necessária para cada finalidade:', ''];
        foreach ($operators as $op) {
            $country = Inventory::countryLabel($op['country']);
            $abroad = Inventory::isAbroad($op['country']) ? ' (transferência internacional)' : '';
            $lines[] = '- ' . $op['name'] . ($country !== '' ? ', ' . $country : '') . $abroad . ': ' . implode('; ', array_filter($op['purposes'])) . '.';
        }

        return implode("\n", $lines);
    }
}
