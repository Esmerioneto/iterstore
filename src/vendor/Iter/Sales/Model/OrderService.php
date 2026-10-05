<?php

declare(strict_types=1);

namespace Iter\Sales\Model;

use Iter\Core\Config;
use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Core\Log;
use Iter\Inventory\Model\StockService;
use Iter\Mail\Model\MailException;
use Iter\Mail\Model\MailService;

/**
 * The order (sales_order, COMMERCE-PLAN §3 and §6). place() turns a complete cart into an order in one
 * transaction: the stock is reserved, everything the customer saw is copied, the cart is closed.
 * Business rules live here, not in the API, so Iter_AdminSales can reuse them.
 */
class OrderService
{
    public const STATUS_LABELS = [
        'pending_payment' => 'Aguardando pagamento',
        'processing' => 'Pagamento confirmado',
        'shipped' => 'Enviado',
        'complete' => 'Concluído',
        'canceled' => 'Cancelado',
    ];

    /** address fields with a column in sales_order_address; the set's other fields go to its attributes (JSON) */
    private const ADDRESS_COLUMNS = ['postcode', 'street', 'number', 'complement', 'district', 'city', 'region', 'recipient', 'telephone'];

    /**
     * The checkout. A second call for the same cart (double click, retry) waits for the first and gets
     * the order it placed. The "pedido recebido" e-mail is queued after the commit.
     * ponytail: a logged-in customer retrying after the first call finished gets the new, empty cart
     * (CART_EMPTY); the storefront then reads the order from the customer's orders (delivery 7)
     *
     * @return int the order id
     * @throws SalesException what the cart is missing; InventoryException INSUFFICIENT_STOCK when another order took the stock first
     */
    public function place(int $quoteId): int
    {
        [$orderId, $placed] = Connection::get()->adapter()->transaction(function ($db) use ($quoteId): array {
            $db->fetchOne($db->select()->from('sales_quote', ['quote_id'])->where('quote_id = ?', $quoteId)->forUpdate());
            $existing = $db->fetchOne($db->select()->from('sales_order', ['order_id'])->where('quote_id = ?', $quoteId));
            if ($existing !== false) {
                return [(int) $existing, false];
            }
            $cart = (new CartService())->view($quoteId);
            self::assertComplete($cart);
            $websiteId = $cart['website_id'];
            $number = $this->nextNumber($websiteId);
            (new StockService())->reserve($websiteId, array_column($cart['items'], 'quantity', 'product_id'), 'order', $number);

            $order = $db->insert('sales_order', [
                'increment_id' => $number,
                'website_id' => $websiteId,
                'quote_id' => $quoteId,
                'customer_id' => $cart['customer_id'],
                'email' => $cart['email'],
                'firstname' => $cart['firstname'],
                'lastname' => $cart['lastname'],
                'taxvat' => $cart['taxvat'],
                'subtotal' => $cart['subtotal'],
                'shipping_amount' => $cart['shipping_amount'],
                'grand_total' => $cart['total'],
                'shipping_method' => $cart['shipping_method']['code'] ?? null,
                'shipping_label' => $cart['shipping_method']['label'] ?? null,
                'shipping_days' => $cart['shipping_method']['days'] ?? null,
                'payment_method' => $cart['payment_method']['code'] ?? null,
                'payment_label' => $cart['payment_method']['label'] ?? null,
                'payment_instructions' => $cart['payment_method']['instructions'] ?? null,
            ]);
            $orderId = (int) $order['order_id'];
            $options = $this->variantOptions(array_column($cart['items'], 'product_id'));
            $db->insertMultiple('sales_order_item', array_map(static fn (array $i) => [
                'order_id' => $orderId,
                'product_id' => $i['product_id'],
                'sku' => $i['sku'],
                'name' => $i['name'],
                'options' => $options[$i['product_id']] ?? null,
                'quantity' => $i['quantity'],
                'price' => $i['price'],
                'row_total' => $i['row_total'],
            ], $cart['items']));
            foreach (['shipping' => $cart['shipping_address'], 'billing' => $cart['billing_address']] as $type => $address) {
                $db->insert('sales_order_address', ['order_id' => $orderId, 'type' => $type]
                    + array_map(static fn ($v) => $v === '' ? null : $v, array_intersect_key($address, array_flip(self::ADDRESS_COLUMNS)))
                    + ['attributes' => json_encode((object) array_diff_key($address, array_flip(self::ADDRESS_COLUMNS)), JSON_UNESCAPED_UNICODE)]);
            }
            $db->insert('sales_order_status_history', ['order_id' => $orderId, 'status' => 'pending_payment']);
            $db->update('sales_quote', ['is_active' => 0], ['quote_id = ?' => $quoteId]);

            return [$orderId, true];
        });
        if ($placed) {
            $this->mailPlaced($orderId);
        }

        return $orderId;
    }

    /**
     * The order as the API shows it: the copied data, items, addresses (fixed fields + the set's), status label.
     *
     * @throws SalesException ORDER_NOT_FOUND
     */
    public function get(int $orderId): array
    {
        $db = Connection::get()->adapter();
        $order = $db->fetchRow($db->select()->from('sales_order')->where('order_id = ?', $orderId))
            ?: throw new SalesException('ORDER_NOT_FOUND', 'Pedido não encontrado.');
        $addresses = [];
        foreach ($db->fetchAll($db->select()->from('sales_order_address')->where('order_id = ?', $orderId)) as $row) {
            $addresses[$row['type']] = array_intersect_key($row, array_flip(self::ADDRESS_COLUMNS)) + json_decode($row['attributes'], true);
        }
        $items = $db->fetchAll($db->select()->from('sales_order_item')->where('order_id = ?', $orderId)->order('item_id'));

        return $order + [
            'status_label' => self::STATUS_LABELS[$order['status']] ?? $order['status'],
            'items' => array_map(static fn (array $i) => ['quantity' => (float) $i['quantity'], 'price' => (float) $i['price'], 'row_total' => (float) $i['row_total']] + $i, $items),
            'shipping_address' => $addresses['shipping'] ?? null,
            'billing_address' => $addresses['billing'] ?? null,
        ];
    }

    /** @throws SalesException the first thing the cart is missing, with the call that fixes it */
    private static function assertComplete(array $cart): void
    {
        $guest = $cart['customer_id'] === null;
        $error = match (true) {
            !$cart['items'] => ['CART_EMPTY', 'O carrinho está vazio.'],
            $cart['has_errors'] => ['CART_HAS_ERRORS', current(array_filter(array_column($cart['items'], 'error')))],
            $guest && !Config::get('sales/checkout/guest', $cart['website_id']) => ['LOGIN_REQUIRED', 'Esta loja só vende para clientes cadastrados: faça login.'],
            !$cart['email'] || !$cart['firstname'] || !$cart['lastname'] || !$cart['taxvat'] => $guest
                ? ['CUSTOMER_INFO_REQUIRED', 'Informe e-mail, nome, sobrenome e CPF/CNPJ (setCartGuestInfo).']
                : ['CUSTOMER_INFO_REQUIRED', 'Complete nome, sobrenome e CPF/CNPJ na conta (updateMe).'],
            $cart['shipping_address'] === null => ['SHIPPING_ADDRESS_REQUIRED', 'Informe o endereço de entrega (setCartShippingAddress).'],
            \isModuleEnabled('iter_shipping') && $cart['shipping_method'] === null => ['SHIPPING_METHOD_REQUIRED', 'Escolha o frete (setCartShippingMethod).'],
            \isModuleEnabled('iter_payment') && $cart['payment_method'] === null => ['PAYMENT_METHOD_REQUIRED', 'Escolha a forma de pagamento (setCartPaymentMethod).'],
            default => null,
        };
        if ($error !== null) {
            throw new SalesException(...$error);
        }
    }

    /** "SP-000001": the website's prefix (Configurações → Vendas) + its next number */
    private function nextNumber(int $websiteId): string
    {
        $next = Connection::get()->adapter()->fetchOne(
            'INSERT INTO sales_order_sequence (website_id, last_value) VALUES (?, 1)
                ON CONFLICT (website_id) DO UPDATE SET last_value = sales_order_sequence.last_value + 1 RETURNING last_value',
            [$websiteId]
        );

        return trim((string) Config::get('sales/order/prefix', $websiteId)) . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    /** @return array<int, string> variant id => "Cor: Preto, Tamanho: M" */
    private function variantOptions(array $productIds): array
    {
        $db = Connection::get()->adapter();

        return $db->fetchPairs($db->select()->from(['vo' => 'catalog_product_variant_option'], ['vo.product_id'])
            ->join(['o' => 'eav_attribute_option'], 'o.option_id = vo.option_id', [])
            ->join(['a' => 'eav_attribute'], 'a.attribute_id = o.attribute_id', [])
            ->columns(['options' => new Expr("string_agg(a.label || ': ' || o.label, ', ' ORDER BY a.attribute_id)")])
            ->where('vo.product_id IN (?)', array_map('intval', $productIds))
            ->group('vo.product_id'));
    }

    private function mailPlaced(int $orderId): void
    {
        $order = $this->get($orderId);
        $money = static fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
        $rows = '';
        foreach ($order['items'] as $item) {
            $name = htmlspecialchars($item['name'] . ($item['options'] ? " ({$item['options']})" : ''));
            $rows .= sprintf('<tr><td>%s</td><td>%s</td><td>%s</td></tr>', $name, str_replace('.', ',', StockService::format((string) $item['quantity'])), $money($item['row_total']));
        }
        try {
            (new MailService())->send('sales_order_placed', $order['email'], [
                'name' => $order['firstname'],
                'number' => $order['increment_id'],
                'items' => $rows,
                'subtotal' => $money($order['subtotal']),
                'shipping' => trim(($order['shipping_label'] ?? '') . ' ' . $money($order['shipping_amount'])),
                'total' => $money($order['grand_total']),
                'payment' => (string) $order['payment_label'],
                'instructions' => (string) $order['payment_instructions'],
            ], ['website_id' => (int) $order['website_id']]);
        } catch (MailException $e) {
            // the order stands; the admin sees the failure in the log
            Log::error("Mail: pedido {$order['increment_id']} sem o e-mail de pedido recebido: " . $e->getMessage());
        }
    }
}
