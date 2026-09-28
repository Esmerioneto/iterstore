<?php

declare(strict_types=1);

namespace Iter\Catalog\View;

use Iter\Catalog\Model\Product;
use Iter\Catalog\Model\ProductService;
use Iter\Core\View\ViewComponentInterface;
use Iter\Eav\Form\FormSchemaGenerator;
use Iter\Eav\Model\AttributeSet;

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

        return [
            'is_edit' => $product !== null,
            'product_id' => $id,
            'values' => array_merge(['sku' => '', 'name' => '', 'is_active' => 1], $product ?? [], $old ?? []),
            'sets' => $sets,
            'attribute_set_id' => $setId,
            'schema' => (new FormSchemaGenerator())->generate(Product::ENTITY_TYPE, $sets[$setId]['code']),
            'attribute_values' => $old['attributes'] ?? ($id ? (new ProductService())->getValues($id) : []),
        ];
    }

    /** @return array<int, array<string, mixed>> the product entity's attribute sets, by id */
    public static function productSets(): array
    {
        $sets = array_filter((new AttributeSet())->getResource()->getList(), static fn ($s) => $s['entity_type'] === Product::ENTITY_TYPE);

        return array_column($sets, null, 'attribute_set_id');
    }
}
