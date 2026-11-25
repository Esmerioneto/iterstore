<?php

declare(strict_types=1);

namespace Iter\Lgpd\View;

use Iter\Core\Config;
use Iter\Core\View\ViewComponentInterface;
use Iter\Lgpd\Model\Inventory;
use Iter\Lgpd\Model\PolicyService;
use Iter\Store\Model\Website;

/** LGPD → Política de privacidade: the editor of one website, the version in force and the published ones */
final class PolicyEditor implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'lgpd/policy';
    }

    public function getData(): array
    {
        $websites = (new Website())->getList();
        $ids = array_map('intval', array_column($websites, 'website_id'));
        $asked = (int) ($_GET['website_id'] ?? 0);
        $default = (int) (array_values(array_filter($websites, static fn ($w) => $w['is_default']))[0]['website_id'] ?? ($ids[0] ?? 0));
        $websiteId = in_array($asked, $ids, true) ? $asked : $default;
        $service = new PolicyService();

        return [
            'websites' => array_column($websites, 'name', 'website_id'),
            'websiteId' => $websiteId,
            'current' => $service->current($websiteId),
            'draft' => $service->draft($websiteId),
            'versions' => $service->versions($websiteId),
            'dpo' => ['name' => (string) Config::get('lgpd/dpo/name', $websiteId), 'email' => (string) Config::get('lgpd/dpo/email', $websiteId)],
            'operatorsText' => InventoryList::policyText(Inventory::operators()),
            'maxLength' => PolicyService::MAX_LENGTH,
        ];
    }
}
