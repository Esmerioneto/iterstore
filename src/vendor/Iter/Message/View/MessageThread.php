<?php

declare(strict_types=1);

namespace Iter\Message\View;

use Iter\Core\Db\Connection;
use Iter\Core\View\ViewComponentInterface;
use Iter\Customer\Model\CustomerService;
use Iter\Message\Model\MessageService;
use Iter\Sales\Model\OrderService;

/** One customer's conversation with who the customer is beside it (the controller checked the customer exists) */
final class MessageThread implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'message/thread';
    }

    public function getData(): array
    {
        $id = (int) ($_GET['customer_id'] ?? 0);
        $before = (int) ($_GET['before'] ?? 0) ?: null;
        $db = Connection::get()->adapter();
        $customer = $db->fetchRow('SELECT c.customer_id, c.firstname, c.lastname, c.company_name, c.customer_type, c.email, c.taxvat, w.name AS website_name
            FROM customer_flat c JOIN store_website w ON w.website_id = c.website_id WHERE c.customer_id = :id', ['id' => $id]);
        $phone = $db->fetchOne("SELECT telephone FROM customer_address_flat WHERE customer_id = :id AND COALESCE(telephone, '') <> ''
            ORDER BY is_default_shipping DESC, address_id LIMIT 1", ['id' => $id]);
        $messages = (new MessageService())->thread($id, 50, $before);
        $old = $_SESSION['iter_old_input'] ?? null;
        unset($_SESSION['iter_old_input']);

        return [
            'customer' => $customer,
            'taxvat' => self::maskTaxvat((string) $customer['taxvat']),
            'phone' => $phone ?: null,
            'orders' => \isModuleEnabled('iter_sales') ? (new OrderService())->search(['customer_id' => (string) $id], 1, 3) : ['items' => [], 'total' => 0],
            'messages' => $messages,
            // a full page means there may be older ones
            'older' => count($messages) === 50 ? $messages[0]['message_id'] : null,
            'before' => $before,
            'draft' => (string) ($old['body'] ?? ''),
        ];
    }

    /** 123.***.***-00: the screen identifies the customer without showing the whole document */
    public static function maskTaxvat(string $digits): string
    {
        return preg_replace_callback('/^(\d+[.])(.*)(-\d+)$/', static fn (array $m) => $m[1] . preg_replace('/\d/', '*', $m[2]) . $m[3], CustomerService::formatTaxvat($digits)) ?? '';
    }
}
