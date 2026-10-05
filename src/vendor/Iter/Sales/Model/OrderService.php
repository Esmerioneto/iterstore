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

    /** status => the ones it may go to; anything else is ORDER_STATUS_INVALID */
    private const TRANSITIONS = [
        'pending_payment' => ['processing', 'canceled'],
        'processing' => ['shipped', 'canceled'],
        'shipped' => ['complete'],
    ];

    /** the admin's actions and the status each one leads to */
    private const ACTIONS = ['confirm' => 'processing', 'ship' => 'shipped', 'complete' => 'complete', 'cancel' => 'canceled'];

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
            $this->mail('sales_order_placed', $this->get($orderId));
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

        $history = $db->select()->from(['h' => 'sales_order_status_history'])->where('h.order_id = ?', $orderId)->order('h.history_id DESC');
        $history->joinLeft(['u' => 'admin_user'], 'u.user_id = h.admin_user_id', ['admin_username' => 'username']);

        return $order + [
            'status_label' => self::STATUS_LABELS[$order['status']] ?? $order['status'],
            'items' => array_map(static fn (array $i) => ['quantity' => (float) $i['quantity'], 'price' => (float) $i['price'], 'row_total' => (float) $i['row_total']] + $i, $items),
            'shipping_address' => $addresses['shipping'] ?? null,
            'billing_address' => $addresses['billing'] ?? null,
            'history' => $db->fetchAll($history),
            'shipment' => $db->fetchRow($db->select()->from('sales_shipment')->where('order_id = ?', $orderId)) ?: null,
        ];
    }

    /** The actions the status allows now (the screen also checks the admin's permissions) */
    public static function actions(string $status): array
    {
        return array_keys(array_filter(self::ACTIONS, static fn ($to) => in_array($to, self::TRANSITIONS[$status] ?? [], true)));
    }

    /** pending_payment → processing: the payment arrived (manual Pix, deposit, a gateway that didn't confirm by itself) */
    public function confirmPayment(int $orderId, ?string $comment, ?int $adminUserId): void
    {
        $this->transition($orderId, 'processing', $comment, $adminUserId);
    }

    /**
     * processing → shipped: the stock leaves $sourceId (the physical quantity goes down, the reservation is
     * given back), the shipment is recorded and the customer gets the tracking code.
     *
     * @throws SalesException SOURCE_INVALID; InventoryException INSUFFICIENT_STOCK when the source lacks a product
     */
    public function ship(int $orderId, int $sourceId, string $carrier, string $trackingCode, ?int $adminUserId): void
    {
        [$carrier, $trackingCode] = [mb_substr(trim($carrier), 0, 255), mb_substr(trim($trackingCode), 0, 255)];
        $this->transition($orderId, 'shipped', null, $adminUserId, function ($db, array $order) use ($sourceId, $carrier, $trackingCode, $adminUserId): void {
            $source = $db->fetchRow($db->select()->from('inventory_source', ['name'])->where('source_id = ?', $sourceId)->where('website_id = ?', (int) $order['website_id']))
                ?: throw new SalesException('SOURCE_INVALID', 'Escolha uma origem do website do pedido.');
            (new StockService())->deduct($sourceId, (int) $order['website_id'], $this->reserved($order['order_id']), $order['increment_id']);
            $db->insert('sales_shipment', ['order_id' => $order['order_id'], 'source_id' => $sourceId, 'source_name' => $source['name'],
                'carrier' => $carrier ?: null, 'tracking_code' => $trackingCode ?: null, 'admin_user_id' => $adminUserId]);
        });
    }

    /** shipped → complete: delivered */
    public function complete(int $orderId, ?int $adminUserId): void
    {
        $this->transition($orderId, 'complete', null, $adminUserId);
    }

    /** before shipping → canceled: the reservation is given back; the reason goes to the customer */
    public function cancel(int $orderId, string $reason, ?int $adminUserId): void
    {
        $reason = trim($reason) ?: throw new SalesException('REASON_REQUIRED', 'Informe o motivo do cancelamento.');
        $this->transition($orderId, 'canceled', $reason, $adminUserId, function ($db, array $order): void {
            (new StockService())->release((int) $order['website_id'], $this->reserved($order['order_id']), 'canceled', $order['increment_id']);
        });
    }

    /** A note in the history, in any status; $notify e-mails it to the customer */
    public function addComment(int $orderId, string $comment, bool $notify, ?int $adminUserId): void
    {
        $comment = trim($comment) ?: throw new SalesException('COMMENT_REQUIRED', 'Escreva o comentário.');
        $order = $this->get($orderId);
        Connection::get()->adapter()->insert('sales_order_status_history', ['order_id' => $orderId, 'status' => $order['status'], 'comment' => $comment,
            'is_customer_notified' => (int) $notify, 'admin_user_id' => $adminUserId]);
        if ($notify) {
            $this->mail('sales_order_comment', $order, ['comment' => $comment]);
        }
    }

    /**
     * The admin list: filters status, website_id, customer_id, q (number, e-mail or name), from, to (dates).
     *
     * @return array{items: array, total: int, amount: float} amount = the filtered orders' total, canceled ones left out
     */
    public function search(array $filters, int $page, int $limit, string $sort = 'created_at', string $dir = 'desc'): array
    {
        $db = Connection::get()->adapter();
        $select = $db->select()->from(['o' => 'sales_order']);
        foreach (['status' => 'o.status = ?', 'website_id' => 'o.website_id = ?', 'customer_id' => 'o.customer_id = ?', 'from' => 'o.created_at >= ?'] as $key => $where) {
            if (($filters[$key] ?? '') !== '') {
                $select->where($where, $filters[$key]);
            }
        }
        if (($filters['to'] ?? '') !== '') {
            $select->where("o.created_at < CAST(? AS date) + 1", $filters['to']);
        }
        if (($filters['q'] ?? '') !== '') {
            $like = '%' . addcslashes(mb_strtolower($filters['q']), '%_\\') . '%';
            $select->where("(LOWER(o.increment_id) LIKE ? OR o.email LIKE ? OR LOWER(o.firstname || ' ' || o.lastname) LIKE ?)", $like); // every ? gets $like
        }
        $totals = $db->fetchRow((clone $select)->reset('columns')->columns(['total' => new Expr('COUNT(*)'),
            'amount' => new Expr("COALESCE(SUM(o.grand_total) FILTER (WHERE o.status <> 'canceled'), 0)")]));
        $column = ['number' => 'o.increment_id', 'customer' => 'o.firstname', 'total' => 'o.grand_total', 'status' => 'o.status'][$sort] ?? 'o.created_at';
        $items = $db->fetchAll($select->order($column . ($dir === 'asc' ? ' ASC' : ' DESC'))->order('o.order_id DESC')->limit($limit, ($page - 1) * $limit));

        return ['items' => $items, 'total' => (int) $totals['total'], 'amount' => (float) $totals['amount']];
    }

    /** The dashboard card: orders today, waiting for payment, to ship, and this month's sales (canceled left out) */
    public function summary(): array
    {
        $db = Connection::get()->adapter();

        return array_map('floatval', $db->fetchRow($db->select()->from('sales_order', [
            'today' => new Expr('COUNT(*) FILTER (WHERE created_at >= CURRENT_DATE)'),
            'pending_payment' => new Expr("COUNT(*) FILTER (WHERE status = 'pending_payment')"),
            'processing' => new Expr("COUNT(*) FILTER (WHERE status = 'processing')"),
            'month' => new Expr("COALESCE(SUM(grand_total) FILTER (WHERE status <> 'canceled' AND created_at >= date_trunc('month', CURRENT_DATE)), 0)"),
        ])));
    }

    /** product id => quantity still reserved by the order (its items whose product still exists) */
    private function reserved(int|string $orderId): array
    {
        $db = Connection::get()->adapter();

        return array_map('floatval', $db->fetchPairs($db->select()->from('sales_order_item', ['product_id', 'quantity' => new Expr('SUM(quantity)')])
            ->where('order_id = ?', (int) $orderId)->where('product_id IS NOT NULL')->group('product_id')));
    }

    /**
     * Moves the order to $to when its current status allows it (ORDER_STATUS_INVALID otherwise), running
     * $change in the same transaction, then e-mails the customer (not on complete: delivered, they know).
     */
    private function transition(int $orderId, string $to, ?string $comment, ?int $adminUserId, ?callable $change = null): void
    {
        Connection::get()->adapter()->transaction(function ($db) use ($orderId, $to, $comment, $adminUserId, $change): void {
            $order = $db->fetchRow($db->select()->from('sales_order')->where('order_id = ?', $orderId)->forUpdate())
                ?: throw new SalesException('ORDER_NOT_FOUND', 'Pedido não encontrado.');
            if (!in_array($to, self::TRANSITIONS[$order['status']] ?? [], true)) {
                throw new SalesException('ORDER_STATUS_INVALID', sprintf('Um pedido "%s" não pode passar para "%s".', self::STATUS_LABELS[$order['status']], self::STATUS_LABELS[$to]));
            }
            $change && $change($db, $order);
            $db->update('sales_order', ['status' => $to], ['order_id = ?' => $orderId]);
            $db->insert('sales_order_status_history', ['order_id' => $orderId, 'status' => $to, 'comment' => $comment ?: null, 'is_customer_notified' => (int) ($to !== 'complete'), 'admin_user_id' => $adminUserId]);
        });
        if ($to === 'complete') {
            return;
        }
        $order = $this->get($orderId);
        $this->mail('sales_order_' . $to, $order, [
            'comment' => (string) $comment,
            'carrier' => (string) ($order['shipment']['carrier'] ?? ''),
            'tracking' => (string) ($order['shipment']['tracking_code'] ?? ''),
        ]);
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

    /** The customer's e-mail about the order: its number, items and totals, plus $vars */
    private function mail(string $template, array $order, array $vars = []): void
    {
        $money = static fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
        $rows = '';
        foreach ($order['items'] as $item) {
            $name = htmlspecialchars($item['name'] . ($item['options'] ? " ({$item['options']})" : ''));
            $rows .= sprintf('<tr><td>%s</td><td>%s</td><td>%s</td></tr>', $name, str_replace('.', ',', StockService::format((string) $item['quantity'])), $money($item['row_total']));
        }
        try {
            (new MailService())->send($template, $order['email'], $vars + [
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
            Log::error("Mail: pedido {$order['increment_id']} sem o e-mail {$template}: " . $e->getMessage());
        }
    }
}
