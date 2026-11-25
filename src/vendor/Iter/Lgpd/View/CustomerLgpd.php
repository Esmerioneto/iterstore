<?php

declare(strict_types=1);

namespace Iter\Lgpd\View;

use Iter\Admin\Auth;
use Iter\Core\Db\Connection;
use Iter\Core\View\ViewComponentInterface;
use Iter\Lgpd\Model\RequestService;

/** "LGPD" under the customer's form (editing only): their requests, and a shortcut to register a new one */
final class CustomerLgpd implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'lgpd/customer-lgpd';
    }

    public function getData(): array
    {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0 || !Auth::isAllowed('Iter_Lgpd::requests')) {
            return ['customerId' => null];
        }

        return [
            'customerId' => $id,
            'anonymizedAt' => Connection::get()->adapter()->fetchOne('SELECT anonymized_at FROM customer_entity WHERE customer_id = :id', ['id' => $id]),
            'requests' => Connection::get()->adapter()->fetchAll('SELECT request_id, type, status, created_at FROM lgpd_request WHERE customer_id = :id ORDER BY request_id DESC LIMIT 10', ['id' => $id]),
            'types' => RequestService::TYPES,
            'statuses' => RequestService::STATUS_LABELS,
        ];
    }
}
