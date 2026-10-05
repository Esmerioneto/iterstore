<?php

declare(strict_types=1);

namespace Iter\Shipping\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Shipping\Model\ShippingService;
use Iter\Store\Model\Website;

class RateForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'shipping/rate-form';
    }

    public function getData(): array
    {
        $websites = array_column((new Website())->getList(), 'name', 'website_id');
        $websiteId = isset($websites[(int) ($_GET['website_id'] ?? 0)]) ? (int) $_GET['website_id'] : (int) (new Website())->getResource()->getDefaultId();

        return ['websites' => $websites, 'website_id' => $websiteId, 'rates' => (new ShippingService())->getTableRates($websiteId)];
    }
}
