<?php

declare(strict_types=1);

namespace Iter\Lgpd\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Store\Model\Website;

/** LGPD → Nova solicitação: a request that reached the store outside the app (e-mail, phone, in person) */
final class RequestForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'lgpd/request-form';
    }

    public function getData(): array
    {
        $old = $_SESSION['iter_old_input'] ?? [];
        unset($_SESSION['iter_old_input']);
        $websites = (new Website())->getList();
        // from the customer's form: ?customer_id= fills e-mail and website (the e-mail never goes in the URL)
        $customer = isset($_GET['customer_id']) ? \Iter\Core\Db\Connection::get()->adapter()->fetchRow('SELECT email, website_id FROM customer_flat WHERE customer_id = :id',
            ['id' => (int) $_GET['customer_id']]) : null;

        return [
            'websites' => array_column($websites, 'name', 'website_id'),
            'values' => $old + ($customer ?: []) + [
                'website_id' => (string) (array_values(array_filter($websites, static fn ($w) => $w['is_default']))[0]['website_id'] ?? ''),
                'email' => '',
                'type' => (string) ($_GET['type'] ?? 'export'),
                'note' => '',
            ],
        ];
    }
}
