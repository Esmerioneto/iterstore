<?php

declare(strict_types=1);

namespace Iter\Adminsales\View;

use Iter\Admin\Auth;
use Iter\Adminsales\Model\AdminCartService;
use Iter\Catalog\Model\Product;
use Iter\Core\Config;
use Iter\Core\View\ViewComponentInterface;
use Iter\Customer\Model\AddressService;
use Iter\Eav\Model\Attribute;
use Iter\Eav\Model\ValueService;
use Iter\Inventory\Model\StockService;
use Iter\Sales\Model\CartService;
use Iter\Sales\Model\OrderService;
use Iter\Store\Model\Website;

/** A cart being built: who, items (with a product search), addresses, shipping, payment and the order button */
class Edit implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'adminsales/edit';
    }

    public function getData(): array
    {
        $quote = (new AdminCartService())->get((int) ($_GET['id'] ?? 0));
        $websiteId = (int) $quote['website_id'];
        $q = trim((string) ($_GET['q'] ?? ''));
        $attributes = new Attribute();
        $types = $attributes->getResource()->getEntityTypes();

        $cart = (new CartService())->view((int) $quote['quote_id']);

        return [
            'quote' => $quote,
            'cart' => $cart,
            'missing' => self::missing($cart),
            'website_name' => (new Website())->load($websiteId)->getData('name'),
            'q' => $q,
            'products' => $q === '' ? [] : $this->products($q, $websiteId),
            'saved_addresses' => $quote['customer_id'] === null ? [] : (new AddressService())->getList((int) $quote['customer_id']),
            'address_labels' => array_column($attributes->getResource()->getByEntityType((int) $types[AddressService::ENTITY_TYPE]['entity_type_id']), 'label', 'code'),
            'address_fields' => AddressService::fields(),
            'regions' => AddressService::REGIONS,
            'guest_allowed' => (bool) Config::get('sales/checkout/guest', $websiteId),
            'can_confirm_payment' => Auth::isAllowed('Iter_Sales::order_payment'),
        ];
    }

    /**
     * What the order still lacks, in this screen's words (OrderService::missing() speaks the API's)
     *
     * @return array<string, string> code => what to do on this page
     */
    public static function missing(array $cart): array
    {
        $texts = [];
        foreach (OrderService::missing($cart) as [$code, $message]) {
            $texts[$code] = match ($code) {
                'CART_EMPTY' => 'Adicione um item (seção Itens).',
                'CART_HAS_ERRORS' => $message,
                'LOGIN_REQUIRED' => 'Este website não vende para visitante (Configurações → Vendas): descarte e comece com um cliente cadastrado.',
                'CUSTOMER_INFO_REQUIRED' => $cart['customer_id'] === null
                    ? 'Salve os dados do visitante (seção Cliente).'
                    : 'O cadastro do cliente está sem nome, sobrenome ou CPF/CNPJ: complete-o em Clientes.',
                'SHIPPING_ADDRESS_REQUIRED' => 'Informe o endereço de entrega.',
                'SHIPPING_METHOD_REQUIRED' => $cart['shipping_methods']
                    ? 'Escolha o frete (seção Frete).'
                    : 'Nenhum frete atende este pedido: confira o endereço, as faixas de frete e Configurações → Frete.',
                'PAYMENT_METHOD_REQUIRED' => $cart['payment_methods']
                    ? 'Escolha a forma de pagamento (seção Pagamento).'
                    : 'Nenhuma forma de pagamento está ativa neste website: ative uma em Configurações → Pagamento.',
                default => $message,
            };
        }

        return $texts;
    }

    /**
     * Active products of the website matching $q (SKU, name, a variant's SKU); a configurable one lists its
     * active variants. Each sellable row: sku, name, options, price, salable.
     *
     * @return array<int, array{sku: string, name: string, variants: array<int, array>}>
     */
    private function products(string $q, int $websiteId): array
    {
        $resource = (new Product())->getResource();
        $parents = $resource->search(['q' => $q, 'website_id' => (string) $websiteId, 'is_active' => '1'], 1, 10)['items'];
        $children = $resource->getVariantRowsMany(array_column(array_filter($parents, static fn ($p) => $p['type'] === 'configurable'), 'product_id'));
        $sellable = [];
        foreach ($parents as $parent) {
            $rows = $parent['type'] === 'configurable'
                ? array_filter($children[(int) $parent['product_id']] ?? [], static fn ($v) => (bool) $v['is_active'])
                : [$parent];
            foreach ($rows as $row) {
                $sellable[(int) $row['product_id']] = ['parent' => (int) $parent['product_id']] + $row;
            }
        }
        $ids = array_keys($sellable);
        $salable = (new StockService())->getSalable($ids, $websiteId);
        $prices = (new ValueService())->loadMany(Product::ENTITY_TYPE, $ids, ['price']);
        $options = (new OrderService())->variantOptions($ids);
        $result = [];
        foreach ($parents as $parent) {
            $result[] = ['sku' => $parent['sku'], 'name' => $parent['name'], 'variants' => array_values(array_map(static fn ($row) => [
                'sku' => $row['sku'],
                'options' => $options[(int) $row['product_id']] ?? null,
                'price' => $prices[(int) $row['product_id']]['price'] ?? null,
                'salable' => (float) ($salable[(int) $row['product_id']] ?? 0),
            ], array_filter($sellable, static fn ($row) => $row['parent'] === (int) $parent['product_id'])))];
        }

        return $result;
    }
}
