<?php

declare(strict_types=1);

namespace Iter\Catalog\View;

use Iter\Admin\Auth;
use Iter\Catalog\Model\Category;
use Iter\Catalog\Model\Product;
use Iter\Catalog\Model\ProductService;
use Iter\Catalog\Model\VariantService;
use Iter\Core\View\ViewComponentInterface;
use Iter\Eav\Form\FormSchemaGenerator;
use Iter\Eav\Model\AttributeSet;
use Iter\Eav\Model\FormLayout;
use Iter\Store\Model\Website;

class ProductForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'catalog/product-form';
    }

    public function getData(): array
    {
        $id = !empty($_GET['id']) ? (int) $_GET['id'] : null;
        $model = new Product();
        $product = $id && $model->load($id)->getId() ? $model->getData() : null;

        $old = $_SESSION['iter_old_input'] ?? null;
        unset($_SESSION['iter_old_input']);

        $sets = self::productSets();
        // the set is fixed once the product exists; a new one uses ?set= or the default
        $setId = (int) ($product['attribute_set_id'] ?? $_GET['set'] ?? 0);
        if (!isset($sets[$setId])) {
            $setId = (int) key(array_filter($sets, static fn ($s) => $s['is_default']));
        }

        // a configurable product has no EAV fields (its variants do); a variant's grid values are fixed
        $type = $product['type'] ?? (($_GET['type'] ?? '') === 'configurable' ? 'configurable' : 'simple');
        $variantService = new VariantService();
        $parent = !empty($product['parent_id']) ? (new Product())->load((int) $product['parent_id'])->getData() : null;
        $grid = $type === 'configurable' && $id ? $variantService->getAttributes($id) : ($parent ? $variantService->getAttributes((int) $parent['product_id']) : []);
        $schema = (new FormSchemaGenerator())->generate(Product::ENTITY_TYPE, $sets[$setId]['code']);
        $schema['fields'] = $type === 'configurable' ? [] : array_values(array_filter($schema['fields'], static fn ($f) => !in_array($f['name'], array_column($grid, 'code'), true)));

        return [
            'is_edit' => $product !== null,
            'type' => $type,
            'parent' => $parent,
            'grid' => $grid,
            'variants' => $type === 'configurable' && $id ? $variantService->getVariants($id) : [],
            // new configurable product: the set's select fields it can pick
            'variant_candidates' => $type === 'configurable' && !$id ? $variantService->candidates($sets[$setId]['code']) : [],
            'variant_attributes' => array_map('strval', (array) ($old['variant_attributes'] ?? [])),
            'product_id' => $id,
            'values' => array_merge(['sku' => '', 'name' => '', 'url_key' => '', 'is_active' => 1], $product ?? [], $old ?? []),
            'sets' => $sets,
            'attribute_set_id' => $setId,
            'categories' => (new Category())->getResource()->getTree(),
            'websites' => array_column((new Website())->getList(), 'name', 'website_id'),
            // a new product starts in the default website
            'website_ids' => array_map('intval', (array) ($old['website_ids'] ?? ($id ? (new ProductService())->getWebsiteIds($id) : [(new Website())->getResource()->getDefaultId()]))),
            // without the permission, the default layout (even if one was saved before it was revoked)
            'can_arrange' => $canArrange = Auth::isAllowed('Iter_Catalog::product_layout'),
            'layout' => (new FormLayout(Product::ENTITY_TYPE))->get($canArrange ? (int) Auth::user()->getId() : null),
            'category_ids' => array_map('intval', (array) ($old['category_ids'] ?? ($id ? (new ProductService())->getCategoryIds($id) : []))),
            'schema' => $schema,
            // typed values over the stored ones; file fields keep showing the stored file
            'attribute_values' => array_merge(
                $id ? (new ProductService())->getValues($id) : [],
                array_filter($old['attributes'] ?? [], static fn ($v) => !is_array($v) || array_is_list($v))
            ),
        ];
    }

    /** @return array<int, array<string, mixed>> the product entity's attribute sets, by id */
    public static function productSets(): array
    {
        $sets = array_filter((new AttributeSet())->getResource()->getList(), static fn ($s) => $s['entity_type'] === Product::ENTITY_TYPE);

        return array_column($sets, null, 'attribute_set_id');
    }
}
