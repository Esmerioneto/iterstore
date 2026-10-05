<?php

declare(strict_types=1);

namespace Iter\Adminsales\Model;

use Iter\Core\Db\Connection;
use Iter\Customer\Model\Customer;
use Iter\Sales\Model\OrderService;
use Iter\Sales\Model\SalesException;

/**
 * Orders the admin creates (ADMINSALES-PLAN): a cart of its own (sales_quote.admin_user_id), with a cartId
 * like a guest's and, for a customer, the customer too, apart from the cart the customer builds in the
 * store. Items, addresses, shipping and payment go through Sales' CartService; place() through
 * OrderService. Permissions are checked by the callers (screen and API), like Sales' services.
 */
class AdminCartService
{
    /**
     * @param ?int $customerId null = a guest (setGuestInfo); a customer of $websiteId
     * @return string the cartId
     * @throws SalesException CUSTOMER_NOT_FOUND
     */
    public function create(int $websiteId, ?int $customerId, int $adminUserId): string
    {
        if ($customerId !== null) {
            $customer = (new Customer())->load($customerId);
            if (!$customer->getId() || (int) $customer->getData('website_id') !== $websiteId) {
                throw new SalesException('CUSTOMER_NOT_FOUND', 'Cliente não encontrado neste website.');
            }
        }
        $token = bin2hex(random_bytes(32));
        Connection::get()->adapter()->insert('sales_quote', ['token_hash' => hash('sha256', $token), 'website_id' => $websiteId,
            'customer_id' => $customerId, 'admin_user_id' => $adminUserId]);

        return $token;
    }

    /**
     * An admin's cart still being built (any admin's: a colleague can finish it)
     *
     * @throws SalesException CART_NOT_FOUND
     */
    public function resolve(string $cartId): int
    {
        $db = Connection::get()->adapter();
        $id = $db->fetchOne($db->select()->from('sales_quote', ['quote_id'])->where('token_hash = ?', hash('sha256', $cartId))
            ->where('admin_user_id IS NOT NULL')->where('is_active = 1'));

        return $id !== false ? (int) $id : throw new SalesException('CART_NOT_FOUND', 'Pedido em montagem não encontrado.');
    }

    /**
     * The order from the admin's cart. $paymentReceived (balcão, dinheiro) confirms the payment at once;
     * $comment goes to the history without telling the customer.
     *
     * @return int the order id
     */
    public function place(int $quoteId, int $adminUserId, bool $paymentReceived = false, bool $notify = true, string $comment = ''): int
    {
        $orders = new OrderService();
        $orderId = $orders->place($quoteId, $notify);
        if (trim($comment) !== '') {
            $orders->addComment($orderId, $comment, false, $adminUserId);
        }
        if ($paymentReceived && $orders->get($orderId)['status'] === 'pending_payment') { // a retry finds it confirmed
            $orders->confirmPayment($orderId, 'Pagamento recebido no atendimento.', $adminUserId, $notify);
        }

        return $orderId;
    }

    /** @return array<int, array> the admin's carts still being built, newest first (quote_id, website_id, customer_id, email, updated_at) */
    public function openCarts(int $adminUserId): array
    {
        $db = Connection::get()->adapter();

        return $db->fetchAll($db->select()->from('sales_quote', ['quote_id', 'website_id', 'customer_id', 'email', 'firstname', 'lastname', 'updated_at'])
            ->where('admin_user_id = ?', $adminUserId)->where('is_active = 1')->order('updated_at DESC'));
    }

    public function discard(int $quoteId): void
    {
        Connection::get()->adapter()->delete('sales_quote', ['quote_id = ?' => $quoteId, 'admin_user_id IS NOT NULL', 'is_active = 1']);
    }
}
