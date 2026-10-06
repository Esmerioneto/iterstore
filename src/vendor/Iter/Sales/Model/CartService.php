<?php

declare(strict_types=1);

namespace Iter\Sales\Model;

use Iter\Catalog\Model\Product;
use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Customer\Model\AddressService;
use Iter\Customer\Model\Customer;
use Iter\Eav\Model\Taxvat;
use Iter\Eav\Model\ValueService;
use Iter\Inventory\Model\StockService;
use Iter\Payment\Model\PaymentService;
use Iter\Shipping\Model\ShippingService;

/**
 * The cart (sales_quote). The storefront is external: a guest's cart is found by its cartId (only its
 * sha256 is stored), a customer's by the login (one active cart per website). Business rules live
 * here, not in the API, so Iter_AdminSales can reuse them.
 *
 * Prices and stock are read on every view(): an item whose product stopped being sold keeps its line
 * with an error, and the checkout refuses a cart with errors.
 */
class CartService
{
    /** @return string the cartId the storefront keeps (a guest's cart) */
    public function create(int $websiteId): string
    {
        $token = bin2hex(random_bytes(32));
        Connection::get()->adapter()->insert('sales_quote', ['token_hash' => hash('sha256', $token), 'website_id' => $websiteId]);

        return $token;
    }

    /**
     * The cart an API call works on: with $cartId, that active guest cart; without it, the customer's
     * own (created on first use). $placed also finds a guest cart already turned into an order (a
     * placeOrder retry gets that order).
     *
     * $adminCarts: an admin's cart (Iter_Adminsales) is found too; only for an admin allowed to build
     * orders (the API's cart mutations). The store never finds one.
     *
     * @throws SalesException CART_NOT_FOUND, UNAUTHENTICATED
     */
    public function resolve(?string $cartId, ?Customer $customer, bool $placed = false, bool $adminCarts = false): int
    {
        $db = Connection::get()->adapter();
        if ($cartId !== null && $cartId !== '') {
            $select = $db->select()->from('sales_quote', ['quote_id'])->where('token_hash = ?', hash('sha256', $cartId));
            $adminCarts || $select->where('admin_user_id IS NULL');
            $id = $db->fetchOne($placed ? $select : $select->where('is_active = 1'));

            return $id !== false ? (int) $id : throw new SalesException('CART_NOT_FOUND', 'Carrinho não encontrado. Crie outro com createCart.');
        }
        if ($customer === null) {
            throw new SalesException('UNAUTHENTICATED', 'Informe o cartId ou faça login como cliente.');
        }

        return $this->customerCart($customer);
    }

    /**
     * The cart as the storefront shows it: guest or customer data, addresses, items with the current
     * price and stock, totals.
     *
     * @return array{quote_id: int, website_id: int, customer_id: ?int, admin_user_id: ?int, email: ?string, firstname: ?string, lastname: ?string,
     *               taxvat: ?string, shipping_address: ?array, billing_address: ?array, billing_same_as_shipping: bool,
     *               items: array<int, array>, items_quantity: float, subtotal: float, shipping_methods: array, shipping_method: ?array,
     *               shipping_amount: float, payment_methods: array, payment_method: ?array, total: float, has_errors: bool}
     */
    public function view(int $quoteId): array
    {
        $db = Connection::get()->adapter();
        $quote = $db->fetchRow($db->select()->from('sales_quote')->where('quote_id = ?', $quoteId));
        if ($quote['customer_id'] !== null) { // a customer's details come from the account
            $customer = (new Customer())->load((int) $quote['customer_id']);
            $quote = ['email' => $customer->getData('email'), 'firstname' => $customer->getData('firstname'),
                'lastname' => $customer->getData('lastname'), 'taxvat' => $customer->getData('taxvat')] + $quote;
        }
        $items = $this->items($quoteId, (int) $quote['website_id']);
        $subtotal = round(array_sum(array_column($items, 'row_total')), 2);
        $shipping = $quote['shipping_address'] === null ? null : json_decode($quote['shipping_address'], true);
        $view = [
            'website_id' => (int) $quote['website_id'],
            'items' => $items,
            'subtotal' => $subtotal,
            'shipping_address' => $shipping,
        ];
        // the shipping module is optional: without it the cart has no shipping (a store that only sells on pickup could switch it off)
        $rates = \isModuleEnabled('iter_shipping') ? (new ShippingService())->rates($view) : [];
        $method = $rates[(string) $quote['shipping_method']] ?? null; // a method that stopped fitting (new address) is dropped
        $payments = \isModuleEnabled('iter_payment') ? (new PaymentService())->methods($view) : [];

        return [
            'quote_id' => $quoteId,
            'website_id' => (int) $quote['website_id'],
            'customer_id' => $quote['customer_id'] === null ? null : (int) $quote['customer_id'],
            'admin_user_id' => $quote['admin_user_id'] === null ? null : (int) $quote['admin_user_id'], // built by an admin (Iter_Adminsales)
            'email' => $quote['email'],
            'firstname' => $quote['firstname'],
            'lastname' => $quote['lastname'],
            'taxvat' => $quote['taxvat'],
            'shipping_address' => $shipping,
            'billing_address' => $quote['billing_address'] === null ? $shipping : json_decode($quote['billing_address'], true),
            'billing_same_as_shipping' => $quote['billing_address'] === null,
            'items' => $items,
            'items_quantity' => (float) array_sum(array_column($items, 'quantity')),
            'subtotal' => $subtotal,
            'shipping_methods' => array_values($rates),
            'shipping_method' => $method,
            'shipping_amount' => $method['amount'] ?? 0.0,
            'payment_methods' => array_values($payments),
            'payment_method' => $payments[(string) $quote['payment_method']] ?? null, // switched off since: dropped
            'total' => round($subtotal + ($method['amount'] ?? 0.0), 2), // ponytail: discounts join with the promotions plan
            'has_errors' => (bool) array_filter(array_column($items, 'error')),
        ];
    }

    /** Adds to the item already in the cart, if any; the new quantity must fit the website's stock. */
    public function addItem(int $quoteId, string $sku, float|int|string $quantity): void
    {
        $quantity = self::quantity($quantity);
        $productId = (new Product())->getResource()->findIdBySku($sku) ?? throw new SalesException('PRODUCT_NOT_FOUND', "Produto '{$sku}' não encontrado.");
        $db = Connection::get()->adapter();
        $current = (float) $db->fetchOne($db->select()->from('sales_quote_item', ['quantity'])->where('quote_id = ?', $quoteId)->where('product_id = ?', $productId));
        $this->assertSalable($quoteId, $productId, $current + $quantity);
        $db->insertOnConflict('sales_quote_item', ['quote_id' => $quoteId, 'product_id' => $productId, 'quantity' => $current + $quantity], ['quote_id', 'product_id'], ['quantity']);
        $this->touch($quoteId);
    }

    /** 0 removes the item */
    public function updateItem(int $quoteId, int $itemId, float|int|string $quantity): void
    {
        if ((float) $quantity === 0.0) {
            $this->removeItem($quoteId, $itemId);
            return;
        }
        $quantity = self::quantity($quantity);
        $this->assertSalable($quoteId, $this->itemProduct($quoteId, $itemId), $quantity);
        Connection::get()->adapter()->update('sales_quote_item', ['quantity' => $quantity], ['item_id = ?' => $itemId]);
        $this->touch($quoteId);
    }

    public function removeItem(int $quoteId, int $itemId): void
    {
        $this->itemProduct($quoteId, $itemId);
        Connection::get()->adapter()->delete('sales_quote_item', ['item_id = ?' => $itemId]);
        $this->touch($quoteId);
    }

    /**
     * On login: the guest cart's items join the customer's (quantities added up, stock checked later on
     * view) and the guest cart is removed. Its addresses are kept when the customer's cart has none.
     *
     * @return int the customer's cart
     */
    public function merge(string $guestCartId, Customer $customer): int
    {
        $guestId = $this->resolve($guestCartId, null);
        $customerCartId = $this->customerCart($customer);
        $db = Connection::get()->adapter();
        $guest = $db->fetchRow($db->select()->from('sales_quote')->where('quote_id = ?', $guestId));
        if ((int) $guest['website_id'] !== (int) $customer->getData('website_id')) {
            throw new SalesException('CART_WEBSITE_MISMATCH', 'O carrinho é de outra loja.');
        }
        $db->transaction(static function ($db) use ($guest, $guestId, $customerCartId): void {
            foreach ($db->fetchAll($db->select()->from('sales_quote_item', ['product_id', 'quantity'])->where('quote_id = ?', $guestId)) as $item) {
                $db->insertOnConflict('sales_quote_item', ['quote_id' => $customerCartId, 'product_id' => (int) $item['product_id'], 'quantity' => $item['quantity']],
                    ['quote_id', 'product_id'], ['quantity' => new Expr('sales_quote_item.quantity + EXCLUDED.quantity')]);
            }
            foreach (['shipping_address', 'billing_address'] as $column) {
                if ($guest[$column] !== null) {
                    $db->update('sales_quote', [$column => $guest[$column]], ['quote_id = ?' => $customerCartId, "{$column} IS NULL"]);
                }
            }
            $db->delete('sales_quote', ['quote_id = ?' => $guestId]);
        });
        $this->touch($customerCartId);

        return $customerCartId;
    }

    /** A guest's details for the order (the customer's come from the account) */
    public function setGuestInfo(int $quoteId, string $email, string $firstname, string $lastname, string $taxvat): void
    {
        if ($this->quote($quoteId)['customer_id'] !== null) {
            throw new SalesException('CART_IS_CUSTOMERS', 'O carrinho é de um cliente: os dados vêm da conta (updateMe).');
        }
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            throw new SalesException('EMAIL_INVALID', 'Informe um e-mail válido.');
        }
        [$firstname, $lastname] = [trim($firstname), trim($lastname)];
        if ($firstname === '' || $lastname === '' || mb_strlen($firstname) > 255 || mb_strlen($lastname) > 255) {
            throw new SalesException('NAME_REQUIRED', 'Informe o nome e o sobrenome.');
        }
        $digits = Taxvat::normalize($taxvat) ?? throw new SalesException('TAXVAT_INVALID', 'Informe um CPF ou CNPJ válido.');
        Connection::get()->adapter()->update('sales_quote', ['email' => $email, 'firstname' => $firstname, 'lastname' => $lastname, 'taxvat' => $digits], ['quote_id = ?' => $quoteId]);
    }

    /**
     * $type 'shipping' or 'billing'. The address comes typed in ($address) or from the customer's
     * addresses ($customerAddressId, customer carts only); a billing address with neither = same as delivery.
     */
    public function setAddress(int $quoteId, string $type, ?array $address, ?int $customerAddressId = null): void
    {
        $label = $type === 'shipping' ? 'Endereço de entrega' : 'Endereço de cobrança';
        if ($customerAddressId !== null) {
            $customerId = $this->quote($quoteId)['customer_id'] ?? throw new SalesException('UNAUTHENTICATED', 'Só um cliente logado usa um endereço salvo.');
            $saved = array_column((new AddressService())->getList((int) $customerId), null, 'address_id')[$customerAddressId]
                ?? throw new SalesException('ADDRESS_NOT_FOUND', "O endereço {$customerAddressId} não é deste cliente.");
            $address = array_intersect_key($saved, array_flip([...AddressService::FIXED, ...array_column(AddressService::fields(), 'name')]));
        }
        if ($address === null && $type === 'shipping') {
            throw new SalesException('ADDRESS_INVALID', "{$label}: informe o endereço.");
        }
        $value = $address === null ? null : json_encode((new AddressService())->check($address, $label), JSON_UNESCAPED_UNICODE);
        Connection::get()->adapter()->update('sales_quote', ["{$type}_address" => $value], ['quote_id = ?' => $quoteId]);
    }

    /** @throws SalesException SHIPPING_METHOD_UNAVAILABLE when $code isn't among the cart's options now */
    public function setShippingMethod(int $quoteId, string $code): void
    {
        $cart = $this->view($quoteId);
        $available = array_column($cart['shipping_methods'], 'code');
        if (!in_array($code, $available, true)) {
            throw new SalesException('SHIPPING_METHOD_UNAVAILABLE', match (true) {
                (bool) $available => "Frete '{$code}' indisponível para este carrinho. Opções: " . implode(', ', $available) . '.',
                $cart['shipping_address'] === null => 'Nenhum frete disponível: informe o endereço de entrega (setCartShippingAddress).',
                default => 'Nenhum frete atende o endereço de entrega informado.',
            });
        }
        Connection::get()->adapter()->update('sales_quote', ['shipping_method' => $code], ['quote_id = ?' => $quoteId]);
    }

    /** @throws SalesException PAYMENT_METHOD_UNAVAILABLE when $code isn't among the cart's options now */
    public function setPaymentMethod(int $quoteId, string $code): void
    {
        $available = array_column($this->view($quoteId)['payment_methods'], 'code');
        if (!in_array($code, $available, true)) {
            throw new SalesException('PAYMENT_METHOD_UNAVAILABLE', $available
                ? "Pagamento '{$code}' indisponível. Opções: " . implode(', ', $available) . '.'
                : 'Nenhuma forma de pagamento está ativa nesta loja.');
        }
        Connection::get()->adapter()->update('sales_quote', ['payment_method' => $code], ['quote_id = ?' => $quoteId]);
    }

    /** bin/quote-clean.php: guest carts and the admin's (Iter_Adminsales, a customer's too) unused for $days days; the store's customer carts stay */
    public function cleanGuests(int $days): int
    {
        return Connection::get()->adapter()->delete('sales_quote', ['(customer_id IS NULL OR admin_user_id IS NOT NULL)', 'updated_at < ?' => date('Y-m-d H:i:s', time() - max(1, $days) * 86400)]);
    }

    private function customerCart(Customer $customer): int
    {
        $db = Connection::get()->adapter();
        $find = static fn () => $db->fetchOne($db->select()->from('sales_quote', ['quote_id'])
            ->where('customer_id = ?', (int) $customer->getId())->where('website_id = ?', (int) $customer->getData('website_id'))->where('is_active = 1')->where('admin_user_id IS NULL'));
        $id = $find();
        if ($id === false) {
            // a concurrent first request creates it too; the partial unique index keeps one
            $db->insertOnConflict('sales_quote', ['customer_id' => (int) $customer->getId(), 'website_id' => (int) $customer->getData('website_id')], []);
            $id = $find();
        }

        return (int) $id;
    }

    /** @return array<int, array{id: int, product_id: int, sku: string, name: string, quantity: float, price: ?float, row_total: float, error: ?string, error_code: ?string}> */
    private function items(int $quoteId, int $websiteId): array
    {
        $db = Connection::get()->adapter();
        $rows = $db->fetchAll($db->select()->from(['i' => 'sales_quote_item'], ['item_id', 'product_id', 'quantity'])->where('i.quote_id = ?', $quoteId)->order('i.item_id'));
        $salable = (new StockService())->getSalable(array_column($rows, 'product_id'), $websiteId);
        $products = $this->products(array_column($rows, 'product_id'), $websiteId);
        $items = [];
        foreach ($rows as $row) {
            $product = $products[(int) $row['product_id']];
            $quantity = (float) $row['quantity'];
            [$code, $error] = self::error($product, $quantity, (float) $salable[(int) $row['product_id']]) ?? [null, null];
            $items[] = [
                'id' => (int) $row['item_id'],
                'product_id' => (int) $row['product_id'],
                'sku' => $product['sku'],
                'name' => $product['name'],
                'quantity' => $quantity,
                'price' => $product['price'],
                'row_total' => round((float) $product['price'] * $quantity, 2),
                'weight' => $product['weight'] === null ? 0.0 : (float) $product['weight'],
                'error' => $error,
                'error_code' => $code,
            ];
        }

        return $items;
    }

    /** @return array<int, array> product id => sku, name, type, sellable flags and price */
    private function products(array $ids, int $websiteId): array
    {
        if (!$ids) {
            return [];
        }
        $db = Connection::get()->adapter();
        $select = $db->select()->from(['p' => 'catalog_product'], ['product_id', 'sku', 'name', 'type', 'is_active']);
        $select->joinLeft(['pp' => 'catalog_product'], 'pp.product_id = p.parent_id', ['parent_active' => new Expr('COALESCE(pp.is_active, 1)')])
            ->columns(['in_website' => new Expr('EXISTS (SELECT 1 FROM catalog_product_website w WHERE w.product_id = p.product_id AND w.website_id = ' . $select->bindValue($websiteId) . ')')])
            ->where('p.product_id IN (?)', array_map('intval', $ids));
        $values = (new ValueService())->loadMany(Product::ENTITY_TYPE, $ids, [...Product::PRICE_CODES, 'weight']);

        return array_map(static fn (array $p) => $p + ['price' => Product::finalPrice($values[(int) $p['product_id']] ?? []), 'weight' => $values[(int) $p['product_id']]['weight'] ?? null],
            array_column($db->fetchAll($select), null, 'product_id'));
    }

    /** @return ?array{0: string, 1: string} [code, message] when the item can't be bought as it is */
    private static function error(array $product, float $quantity, float $salable): ?array
    {
        return match (true) {
            $product['type'] === 'configurable' => ['PRODUCT_IS_CONFIGURABLE', "'{$product['name']}' tem grade: escolha a variação (cor, tamanho...)."],
            !$product['is_active'] || !$product['parent_active'] || !$product['in_website'] => ['PRODUCT_NOT_SALABLE', "'{$product['name']}' não está à venda nesta loja."],
            $product['price'] === null => ['PRODUCT_NOT_SALABLE', "'{$product['name']}' está sem preço."],
            $quantity > $salable => ['INSUFFICIENT_STOCK', sprintf("'%s': só há %s disponível.", $product['name'], str_replace('.', ',', StockService::format((string) max(0, $salable))))],
            default => null,
        };
    }

    private function assertSalable(int $quoteId, int $productId, float $quantity): void
    {
        $websiteId = (int) $this->quote($quoteId)['website_id'];
        $error = self::error($this->products([$productId], $websiteId)[$productId], $quantity, (float) (new StockService())->getSalable([$productId], $websiteId)[$productId]);
        if ($error !== null) {
            throw new SalesException(...$error);
        }
    }

    /** @return int the item's product; CART_ITEM_NOT_FOUND when it isn't in this cart */
    private function itemProduct(int $quoteId, int $itemId): int
    {
        $db = Connection::get()->adapter();
        $productId = $db->fetchOne($db->select()->from('sales_quote_item', ['product_id'])->where('item_id = ?', $itemId)->where('quote_id = ?', $quoteId));

        return $productId !== false ? (int) $productId : throw new SalesException('CART_ITEM_NOT_FOUND', 'Item não encontrado neste carrinho.');
    }

    private function quote(int $quoteId): array
    {
        $db = Connection::get()->adapter();

        return $db->fetchRow($db->select()->from('sales_quote')->where('quote_id = ?', $quoteId)) ?: throw new SalesException('CART_NOT_FOUND', 'Carrinho não encontrado.');
    }

    /** keeps a used guest cart away from bin/quote-clean.php (the trigger sets updated_at) */
    private function touch(int $quoteId): void
    {
        Connection::get()->adapter()->update('sales_quote', ['is_active' => new Expr('is_active')], ['quote_id = ?' => $quoteId]);
    }

    /** > 0, up to 4 decimals (products sold by weight), as the stock */
    private static function quantity(float|int|string $quantity): float
    {
        $value = (float) str_replace(',', '.', (string) $quantity);
        if ($value <= 0 || $value > 99999999 || round($value, 4) !== $value) {
            throw new SalesException('QUANTITY_INVALID', 'Quantidade inválida: use um número maior que zero, com até 4 casas decimais.');
        }

        return $value;
    }
}
