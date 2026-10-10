<?php

declare(strict_types=1);

namespace Iter\Sales\Model;

use Iter\Core\Config;
use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Core\Db\Select;
use Iter\Core\Log;
use Iter\Inventory\Model\StockService;
use Iter\Mail\Model\MailException;
use Iter\Mail\Model\MailService;
use Iter\Payment\Model\PaymentService;

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

    /** the channel of an order without the X-Sales-Channel header (the app, the only one before the header existed) */
    public const CHANNEL_DEFAULT = 'App mobile';

    /** the channel of the admin's orders (Iter_Adminsales) */
    public const CHANNEL_ADMIN = 'Atendimento';

    /** address fields with a column in sales_order_address; the set's other fields go to its attributes (JSON) */
    private const ADDRESS_COLUMNS = ['postcode', 'street', 'number', 'complement', 'district', 'city', 'region', 'recipient', 'telephone'];

    /**
     * The checkout. A second call for the same cart (double click, retry) waits for the first and gets
     * the order it placed. The "pedido recebido" e-mail is queued after the commit.
     * ponytail: a logged-in customer retrying after the first call finished gets the new, empty cart
     * (CART_EMPTY); the storefront then reads it with myOrders
     *
     * An admin's cart (Iter_Adminsales) keeps who sold it on the order and in the first history line;
     * $notify = false places it without the e-mail.
     *
     * @return int the order id
     * @throws SalesException what the cart is missing; InventoryException INSUFFICIENT_STOCK when another order took the stock first
     */
    public function place(int $quoteId, bool $notify = true): int
    {
        [$orderId, $placed] = Connection::get()->adapter()->transaction(function ($db) use ($quoteId, $notify): array {
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
                'admin_user_id' => $cart['admin_user_id'],
                'sales_channel' => $cart['admin_user_id'] === null ? self::channel() : self::CHANNEL_ADMIN,
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
            $db->insert('sales_order_status_history', ['order_id' => $orderId, 'status' => 'pending_payment', 'is_customer_notified' => (int) $notify]
                + ($cart['admin_user_id'] === null ? [] : ['comment' => 'Pedido criado pelo atendimento.', 'admin_user_id' => $cart['admin_user_id']]));
            $db->update('sales_quote', ['is_active' => 0], ['quote_id = ?' => $quoteId]);

            return [$orderId, true];
        });
        if ($placed && $notify) {
            $this->mail('sales_order_placed', $this->get($orderId));
        }

        return $orderId;
    }

    /**
     * The request's X-Sales-Channel header ("App mobile", "Web"...): free text, one line, up to 64
     * characters; none or blank = CHANNEL_DEFAULT
     */
    public static function channel(): string
    {
        $channel = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', mb_scrub((string) ($_SERVER['HTTP_X_SALES_CHANNEL'] ?? ''), 'UTF-8'))), 0, 64);

        return $channel === '' ? self::CHANNEL_DEFAULT : $channel;
    }

    /** @return string[] the channels that have orders, for the list's filter */
    public function channels(): array
    {
        $db = Connection::get()->adapter();

        return $db->fetchCol($db->select()->from('sales_order', ['sales_channel'])->distinct()->order('sales_channel'));
    }

    /**
     * The order as the API shows it: the copied data, items, addresses (fixed fields + the set's), status label.
     *
     * @throws SalesException ORDER_NOT_FOUND
     */
    public function get(int $orderId): array
    {
        return $this->getMany([$orderId])[$orderId] ?? throw new SalesException('ORDER_NOT_FOUND', 'Pedido não encontrado.');
    }

    /**
     * get() for a list's page: one query per table for all the orders
     *
     * @param int[] $orderIds
     * @return array<int, array> order id => order, in the order of $orderIds; unknown ids left out
     */
    public function getMany(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if (!$orderIds) {
            return [];
        }
        $db = Connection::get()->adapter();
        $byOrder = static function (array $rows): array {
            $grouped = [];
            foreach ($rows as $row) {
                $grouped[(int) $row['order_id']][] = $row;
            }
            return $grouped;
        };
        // the store (website) that took the order: Loja RJ, Loja SP...
        $orders = array_column($db->fetchAll($db->select()->from(['o' => 'sales_order'])->where('o.order_id IN (?)', $orderIds)
            ->joinLeft(['w' => 'store_website'], 'w.website_id = o.website_id', ['website_name' => 'name'])), null, 'order_id');
        $items = $byOrder($db->fetchAll($db->select()->from('sales_order_item')->where('order_id IN (?)', $orderIds)->order('item_id')));
        $addresses = $byOrder($db->fetchAll($db->select()->from('sales_order_address')->where('order_id IN (?)', $orderIds)));
        $history = $db->select()->from(['h' => 'sales_order_status_history'])->where('h.order_id IN (?)', $orderIds)->order('h.history_id DESC');
        $history = $byOrder($db->fetchAll($history->joinLeft(['u' => 'admin_user'], 'u.user_id = h.admin_user_id', ['admin_username' => 'username'])));
        $shipments = array_column($db->fetchAll($db->select()->from('sales_shipment')->where('order_id IN (?)', $orderIds)), null, 'order_id');

        $result = [];
        foreach ($orderIds as $id) {
            if (!isset($orders[$id])) {
                continue;
            }
            $address = [];
            foreach ($addresses[$id] ?? [] as $row) {
                $address[$row['type']] = array_intersect_key($row, array_flip(self::ADDRESS_COLUMNS)) + json_decode($row['attributes'], true);
            }
            $result[$id] = $orders[$id] + [
                'status_label' => self::STATUS_LABELS[$orders[$id]['status']] ?? $orders[$id]['status'],
                'items' => array_map(static fn (array $i) => ['quantity' => (float) $i['quantity'], 'price' => (float) $i['price'], 'row_total' => (float) $i['row_total']] + $i, $items[$id] ?? []),
                'shipping_address' => $address['shipping'] ?? null,
                'billing_address' => $address['billing'] ?? null,
                'history' => $history[$id] ?? [],
                'shipment' => $shipments[$id] ?? null,
            ];
        }

        return $result;
    }

    /** The actions the status allows now (the screen also checks the admin's permissions) */
    public static function actions(string $status): array
    {
        return array_keys(array_filter(self::ACTIONS, static fn ($to) => in_array($to, self::TRANSITIONS[$status] ?? [], true)));
    }

    /** pending_payment → processing: the payment arrived (manual Pix, deposit, a gateway that didn't confirm by itself) */
    public function confirmPayment(int $orderId, ?string $comment, ?int $adminUserId, bool $notify = true): void
    {
        $this->transition($orderId, 'processing', $comment, $adminUserId, notify: $notify);
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

    /** The website's sources for shipping the order, the suggested one first (StockService::shipSources) */
    public function shipSources(int $orderId): array
    {
        return (new StockService())->shipSources((int) $this->get($orderId)['website_id'], $this->reserved($orderId));
    }

    /** shipped → complete: delivered */
    public function complete(int $orderId, ?int $adminUserId): void
    {
        $this->transition($orderId, 'complete', null, $adminUserId);
    }

    /**
     * While waiting for payment the customer may switch to another of the website's payment options
     * (e.g. the card was declined).
     * ponytail: a Pix QR Code already issued stays valid; paid later, it still confirms the order
     *
     * @throws SalesException ORDER_NOT_FOUND, ORDER_STATUS_INVALID, PAYMENT_METHOD_UNAVAILABLE
     */
    public function changePaymentMethod(int $orderId, string $code): void
    {
        $order = $this->get($orderId);
        if ($order['status'] !== 'pending_payment') {
            throw new SalesException('ORDER_STATUS_INVALID', 'Este pedido não está aguardando pagamento.');
        }
        $methods = \isModuleEnabled('iter_payment') ? (new PaymentService())->methods(['website_id' => $order['website_id']]) : [];
        $method = $methods[$code] ?? throw new SalesException('PAYMENT_METHOD_UNAVAILABLE', "Pagamento '{$code}' indisponível.");
        $db = Connection::get()->adapter();
        $db->update('sales_order', ['payment_method' => $code, 'payment_label' => $method['label'], 'payment_instructions' => $method['instructions']], ['order_id = ?' => $orderId]);
        $db->insert('sales_order_status_history', ['order_id' => $orderId, 'status' => 'pending_payment', 'is_customer_notified' => 0,
            'comment' => "Forma de pagamento alterada pelo cliente para {$method['label']}."]);
    }

    /** before shipping → canceled: the reservation is given back; the reason goes to the customer */
    public function cancel(int $orderId, string $reason, ?int $adminUserId): void
    {
        $reason = trim($reason) ?: throw new SalesException('REASON_REQUIRED', 'Informe o motivo do cancelamento.');
        $this->transition($orderId, 'canceled', $reason, $adminUserId, function ($db, array $order): void {
            (new StockService())->release((int) $order['website_id'], $this->reserved($order['order_id']), 'canceled', $order['increment_id']);
        });
    }

    /**
     * bin/order-clean.php: cancels the orders still waiting for payment after the hours set for their
     * payment method (Configurações → Pagamento, per website; 0 = never), giving the stock back.
     *
     * @return string[] the canceled orders' numbers
     */
    public function cancelUnpaid(): array
    {
        $db = Connection::get()->adapter();
        $canceled = [];
        foreach ($db->fetchAll($db->select()->from('sales_order', ['order_id', 'increment_id', 'website_id', 'payment_method', 'created_at'])
            ->where("status = 'pending_payment'")->where('payment_method IS NOT NULL')->order('order_id')) as $order) {
            $hours = (int) Config::get("payment/{$order['payment_method']}/cancel_hours", (int) $order['website_id']);
            if ($hours <= 0 || strtotime((string) $order['created_at']) > time() - $hours * 3600) {
                continue;
            }
            try {
                $this->cancel((int) $order['order_id'], "Pagamento não recebido em {$hours} hora(s).", null);
                $canceled[] = $order['increment_id'];
            } catch (SalesException) {
                // paid by the admin in the meantime: ORDER_STATUS_INVALID, nothing to do
            }
        }

        return $canceled;
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
     * The admin list: filters status, website_id, customer_id, sales_channel, q (number, e-mail or name), from, to (dates).
     *
     * @return array{items: array, total: int, amount: float} amount = the filtered orders' total, canceled ones left out
     */
    public function search(array $filters, int $page, int $limit, string $sort = 'created_at', string $dir = 'desc'): array
    {
        $db = Connection::get()->adapter();
        $select = $this->filtered($filters);
        $totals = $db->fetchRow((clone $select)->reset('columns')->columns(['total' => new Expr('COUNT(*)'),
            'amount' => new Expr("COALESCE(SUM(o.grand_total) FILTER (WHERE o.status <> 'canceled'), 0)")]));
        $column = ['number' => 'o.increment_id', 'customer' => 'o.firstname', 'total' => 'o.grand_total', 'status' => 'o.status'][$sort] ?? 'o.created_at';
        $items = $db->fetchAll($select->order($column . ($dir === 'asc' ? ' ASC' : ' DESC'))->order('o.order_id DESC')->limit($limit, ($page - 1) * $limit));

        return ['items' => $items, 'total' => (int) $totals['total'], 'amount' => (float) $totals['amount']];
    }

    /**
     * Count and amount per status of the filtered orders (search()'s filters)
     *
     * @return array<string, array{count: int, amount: float}> status => totals, every status present
     */
    public function totalsByStatus(array $filters): array
    {
        $db = Connection::get()->adapter();
        $rows = $db->fetchAll($this->filtered($filters)->reset('columns')
            ->columns(['o.status', 'count' => new Expr('COUNT(*)'), 'amount' => new Expr('COALESCE(SUM(o.grand_total), 0)')])->group('o.status'));
        $totals = array_fill_keys(array_keys(self::STATUS_LABELS), ['count' => 0, 'amount' => 0.0]);
        foreach ($rows as $row) {
            $totals[$row['status']] = ['count' => (int) $row['count'], 'amount' => (float) $row['amount']];
        }

        return $totals;
    }

    /** sales_order o with search()'s filters */
    private function filtered(array $filters): Select
    {
        $select = Connection::get()->adapter()->select()->from(['o' => 'sales_order']);
        foreach (['status' => 'o.status = ?', 'website_id' => 'o.website_id = ?', 'customer_id' => 'o.customer_id = ?', 'sales_channel' => 'o.sales_channel = ?', 'from' => 'o.created_at >= ?'] as $key => $where) {
            if (($filters[$key] ?? '') !== '') {
                $select->where($where, $filters[$key]);
            }
        }
        if (($filters['to'] ?? '') !== '') {
            $select->where('o.created_at < CAST(? AS date) + 1', $filters['to']);
        }
        if (($filters['q'] ?? '') !== '') {
            $like = '%' . addcslashes(mb_strtolower($filters['q']), '%_\\') . '%';
            $select->where("(LOWER(o.increment_id) LIKE ? OR o.email LIKE ? OR LOWER(o.firstname || ' ' || o.lastname) LIKE ?)", $like); // every ? gets $like
        }

        return $select;
    }

    /**
     * @param array<string, int|string> $match more conditions: customer_id (the customer's own) or email (a guest's lookup, any case)
     * @return ?int the order with this number; $websiteId when two websites share a prefix
     */
    public function findIdByNumber(string $number, ?int $websiteId = null, array $match = []): ?int
    {
        $db = Connection::get()->adapter();
        $select = $db->select()->from('sales_order', ['order_id'])->where('UPPER(increment_id) = UPPER(?)', trim($number))->order('order_id')->limit(1);
        $websiteId === null || $select->where('website_id = ?', $websiteId);
        isset($match['customer_id']) && $select->where('customer_id = ?', (int) $match['customer_id']);
        isset($match['email']) && $select->where('email = ?', mb_strtolower(trim((string) $match['email'])));
        $id = $db->fetchOne($select);

        return $id === false ? null : (int) $id;
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
     * $change in the same transaction, then e-mails the customer (not on complete: delivered, they know; not when !$notify).
     */
    private function transition(int $orderId, string $to, ?string $comment, ?int $adminUserId, ?callable $change = null, bool $notify = true): void
    {
        $notify = $notify && $to !== 'complete';
        Connection::get()->adapter()->transaction(function ($db) use ($orderId, $to, $comment, $adminUserId, $change, $notify): void {
            $order = $db->fetchRow($db->select()->from('sales_order')->where('order_id = ?', $orderId)->forUpdate())
                ?: throw new SalesException('ORDER_NOT_FOUND', 'Pedido não encontrado.');
            if (!in_array($to, self::TRANSITIONS[$order['status']] ?? [], true)) {
                throw new SalesException('ORDER_STATUS_INVALID', sprintf('Um pedido "%s" não pode passar para "%s".', self::STATUS_LABELS[$order['status']], self::STATUS_LABELS[$to]));
            }
            $change && $change($db, $order);
            $db->update('sales_order', ['status' => $to], ['order_id = ?' => $orderId]);
            $db->insert('sales_order_status_history', ['order_id' => $orderId, 'status' => $to, 'comment' => $comment ?: null, 'is_customer_notified' => (int) $notify, 'admin_user_id' => $adminUserId]);
        });
        if (!$notify) {
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
        $missing = self::missing($cart);
        if ($missing) {
            throw new SalesException(...$missing[0]);
        }
    }

    /**
     * Everything the cart still lacks to become an order, in the order to fix it (a screen lists them
     * before the button; place() refuses with the first)
     *
     * @param array $cart CartService::view()
     * @return array<int, array{0: string, 1: string}> [code, message] each
     */
    public static function missing(array $cart): array
    {
        $guest = $cart['customer_id'] === null;
        $customerInfo = $cart['email'] && $cart['firstname'] && $cart['lastname'] && $cart['taxvat'];

        return array_values(array_filter([
            !$cart['items'] ? ['CART_EMPTY', 'O carrinho está vazio.'] : null,
            $cart['has_errors'] ? ['CART_HAS_ERRORS', current(array_filter(array_column($cart['items'], 'error')))] : null,
            // the admin's orders too: a guest sale needs "Vender para visitante" on (ADMINSALES-PLAN decision 3)
            $guest && !Config::get('sales/checkout/guest', $cart['website_id']) ? ['LOGIN_REQUIRED', 'Esta loja só vende para clientes cadastrados: faça login.'] : null,
            !$customerInfo ? ($guest
                ? ['CUSTOMER_INFO_REQUIRED', 'Informe e-mail, nome, sobrenome e CPF/CNPJ (setCartGuestInfo).']
                : ['CUSTOMER_INFO_REQUIRED', 'Complete nome, sobrenome e CPF/CNPJ na conta (updateMe).']) : null,
            $cart['shipping_address'] === null ? ['SHIPPING_ADDRESS_REQUIRED', 'Informe o endereço de entrega (setCartShippingAddress).'] : null,
            \isModuleEnabled('iter_shipping') && $cart['shipping_method'] === null ? ['SHIPPING_METHOD_REQUIRED', 'Escolha o frete (setCartShippingMethod).'] : null,
            \isModuleEnabled('iter_payment') && $cart['payment_method'] === null ? ['PAYMENT_METHOD_REQUIRED', 'Escolha a forma de pagamento (setCartPaymentMethod).'] : null,
        ]));
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
    public function variantOptions(array $productIds): array
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
