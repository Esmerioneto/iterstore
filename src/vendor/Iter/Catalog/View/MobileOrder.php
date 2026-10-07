<?php

declare(strict_types=1);

namespace Iter\Catalog\View;

use Iter\Catalog\Model\MobileService;
use Iter\Core\View\ViewComponentInterface;
use Iter\Store\Model\Website;

class MobileOrder implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'catalog/mobile-order';
    }

    public function getData(): array
    {
        $websites = array_column((new Website())->getList(), 'name', 'website_id');
        $websiteId = isset($websites[(int) ($_GET['website_id'] ?? 0)]) ? (int) $_GET['website_id'] : (int) (new Website())->getResource()->getDefaultId();
        $service = new MobileService();

        return ['websites' => $websites, 'website_id' => $websiteId, 'enabled' => $service->isEnabled($websiteId), 'categories' => $service->screen($websiteId)];
    }
}
