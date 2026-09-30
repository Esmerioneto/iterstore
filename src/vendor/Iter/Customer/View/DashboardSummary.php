<?php

declare(strict_types=1);

namespace Iter\Customer\View;

use Iter\Admin\Auth;
use Iter\Core\View\ViewComponentInterface;
use Iter\Customer\Model\ResourceModel\Customer;

class DashboardSummary implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'customer/dashboard-summary';
    }

    public function getData(): array
    {
        if (!Auth::isAllowed('Iter_Customer::customer')) {
            return ['counts' => null];
        }

        return ['counts' => (new Customer())->getSummary()];
    }
}
