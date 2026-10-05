<?php

declare(strict_types=1);

namespace Iter\Adminsales\View;

use Iter\Admin\Auth;
use Iter\Adminsales\Model\AdminCartService;
use Iter\Core\Config;
use Iter\Core\View\ViewComponentInterface;
use Iter\Customer\Model\Customer;
use Iter\Store\Model\Website;

/** Novo pedido: the admin's carts being built, and a new one for a customer (search) or a guest */
class Start implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'adminsales/start';
    }

    public function getData(): array
    {
        $websites = array_column((new Website())->getList(), 'name', 'website_id');
        $websiteId = isset($websites[(int) ($_GET['website_id'] ?? 0)]) ? (int) $_GET['website_id'] : (int) (new Website())->getResource()->getDefaultId();
        $q = trim((string) ($_GET['q'] ?? ''));

        return [
            'websites' => $websites,
            'website_id' => $websiteId,
            'q' => $q,
            'customers' => $q === '' ? [] : (new Customer())->getResource()->search(['q' => $q, 'website_id' => (string) $websiteId, 'is_active' => '1'], 1, 20)['items'],
            'guest_allowed' => (bool) Config::get('sales/checkout/guest', $websiteId),
            'open' => (new AdminCartService())->openCarts((int) Auth::user()->getId()),
        ];
    }
}
