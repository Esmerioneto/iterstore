<?php

declare(strict_types=1);

namespace Iter\Catalog\Model;

use Iter\Core\Db\Connection;
use Iter\Eav\Form\FormSchemaGenerator;
use Iter\Eav\Model\Attribute;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\ValueService;

/**
 * Variants of a configurable product: simple products with parent_id, one per
 * combination of the parent's variant attributes (1 to 3 select attributes of its
 * set, chosen on creation). A variant keeps its own SKU, stock and EAV values
 * (price, description, images); name, websites and categories come from the parent.
 * The combination is stored in the variant's EAV values; variant_key (a hash of it)
 * lets the database refuse a repeated one.
 */
class VariantService
{
    public const MAX_ATTRIBUTES = 3;

    /** @return array<int, array<string, mixed>> the set's select fields (FormSchemaGenerator fields) */
    public function candidates(string $setCode): array
    {
        $fields = (new FormSchemaGenerator())->generate(Product::ENTITY_TYPE, $setCode)['fields'];

        return array_values(array_filter($fields, static fn ($f) => $f['type'] === 'select'));
    }

    /**
     * @param mixed $codes posted/API attribute codes, in grid order
     * @return int[] their attribute ids
     */
    public function attributeIds(string $setCode, mixed $codes): array
    {
        $codes = array_values(array_unique(array_filter(array_map('strval', (array) $codes), 'strlen')));
        $allowed = array_column($this->candidates($setCode), 'name');
        if (!$codes || count($codes) > self::MAX_ATTRIBUTES || array_diff($codes, $allowed)) {
            throw new AttributeException('VARIANT_ATTRIBUTE_INVALID', 'Escolha de 1 a ' . self::MAX_ATTRIBUTES . ' atributos de seleção do conjunto para formar a grade.');
        }
        $ids = array_column($this->attributeRows(), 'attribute_id', 'code');

        return array_map(static fn ($code) => (int) $ids[$code], $codes);
    }

    /** @return array<int, array{attribute_id: int, code: string, label: string, options: array<int, array{value: string, label: string}>}> in grid order */
    public function getAttributes(int $parentId): array
    {
        $resource = (new Product())->getResource();
        $rows = array_column($this->attributeRows(), null, 'attribute_id');
        $attributes = [];
        foreach ($resource->getVariantAttributeIds($parentId) as $id) {
            $attributes[] = [
                'attribute_id' => $id,
                'code' => $rows[$id]['code'],
                'label' => $rows[$id]['label'],
                'options' => array_map(
                    static fn ($o) => ['value' => $o['value'], 'label' => $o['label']],
                    (new Attribute())->getResource()->getOptions($id)
                ),
            ];
        }

        return $attributes;
    }

    /** @return array<int, array{product_id: int, sku: string, name: string, is_active: int, options: array<string, string>, price: ?string}> */
    public function getVariants(int $parentId): array
    {
        $codes = array_column($this->getAttributes($parentId), 'code');
        $values = new ValueService();
        $variants = [];
        foreach ((new Product())->getResource()->getVariantRows($parentId) as $row) {
            $stored = $values->load(Product::ENTITY_TYPE, (int) $row['product_id']);
            $variants[] = [
                'product_id' => (int) $row['product_id'],
                'sku' => $row['sku'],
                'name' => $row['name'],
                'is_active' => (int) $row['is_active'],
                'options' => array_map(static fn ($code) => (string) ($stored[$code] ?? ''), array_combine($codes, $codes)),
                'price' => isset($stored['price']) ? (string) $stored['price'] : null,
            ];
        }

        return $variants;
    }

    /** "BLUSA-001" + ["Azul Marinho", "P"] -> "BLUSA-001-AZUL-MARINHO-P" */
    public static function suggestSku(string $parentSku, array $optionLabels): string
    {
        return substr(implode('-', array_merge([$parentSku], array_map(static fn ($l) => strtoupper(CategoryService::slug((string) $l)), $optionLabels))), 0, 64);
    }

    /**
     * Replaces the parent's variants with $variants: ids not listed are removed (their
     * stock goes by FK CASCADE), listed ones updated, those without product_id created.
     * A variant's combination is fixed once created.
     *
     * @param array<int, array{product_id?: int, options?: array<string, string>, sku?: string, price?: string, is_active?: int}> $variants
     */
    public function save(int $parentId, array $variants): void
    {
        $parent = (new ProductService())->get($parentId);
        if ($parent->getData('type') !== 'configurable') {
            throw new AttributeException('VARIANT_PARENT_INVALID', 'Só um produto com grade tem variações.');
        }
        $attributes = $this->getAttributes($parentId);
        $current = array_column($this->getVariants($parentId), null, 'product_id');
        $values = new ValueService();

        $rows = [];
        $keys = [];
        $skus = [];
        foreach ($variants as $variant) {
            $id = (int) ($variant['product_id'] ?? 0);
            if ($id && !isset($current[$id])) {
                throw new AttributeException('PRODUCT_NOT_FOUND', "A variação {$id} não é deste produto.");
            }
            $options = $id ? $current[$id]['options'] : $this->options($attributes, (array) ($variant['options'] ?? []));
            $labels = $this->labels($attributes, $options);
            $key = sha1(json_encode($options, JSON_UNESCAPED_UNICODE));
            if (isset($keys[$key])) {
                throw new AttributeException('VARIANT_DUPLICATE', 'A combinação ' . implode(' / ', $labels) . ' aparece mais de uma vez.');
            }
            $keys[$key] = true;

            $sku = trim((string) ($variant['sku'] ?? '')) ?: self::suggestSku((string) $parent->getData('sku'), $labels);
            ProductService::checkSku($sku, $id ?: null);
            if (isset($skus[strtolower($sku)])) {
                throw new AttributeException('SKU_DUPLICATE', "O SKU '{$sku}' aparece em mais de uma variação.");
            }
            $skus[strtolower($sku)] = true;

            $eav = $id ? [] : $options; // the combination is only written on creation
            if (array_key_exists('price', $variant) || !$id) {
                $eav['price'] = $variant['price'] ?? '';
            }
            $rows[] = [
                'id' => $id,
                'fields' => [
                    'sku' => $sku,
                    'name' => mb_substr($parent->getData('name') . ' - ' . implode(' / ', $labels), 0, 255),
                    'is_active' => empty($variant['is_active']) ? 0 : 1,
                ] + ($id ? [] : ['variant_key' => $key]),
                'values' => $values->validate(Product::ENTITY_TYPE, $eav, $id ?: null),
            ];
        }
        $removed = array_diff(array_keys($current), array_column($rows, 'id'));
        $productService = new ProductService();
        $websiteIds = $productService->getWebsiteIds($parentId);
        $categoryIds = $productService->getCategoryIds($parentId);

        Connection::get()->adapter()->transaction(function () use ($parent, $rows, $removed, $values, $websiteIds, $categoryIds): void {
            foreach ($removed as $id) {
                (new Product())->load($id)->delete();
                $values->deleteEntity(Product::ENTITY_TYPE, $id);
            }
            foreach ($rows as $row) {
                $model = $row['id'] ? (new Product())->load($row['id']) : (new Product())->addData([
                    'attribute_set_id' => (int) $parent->getData('attribute_set_id'),
                    'type' => 'simple',
                    'parent_id' => (int) $parent->getId(),
                ]);
                $model->addData($row['fields'])->save();
                $id = (int) $model->getId();
                if (!$row['id']) {
                    $model->getResource()->setWebsites($id, $websiteIds);
                    (new Category())->getResource()->setProductCategories($id, $categoryIds);
                }
                $values->write($id, $row['values']);
            }
        });
    }

    /** the parent's name, websites and categories, copied to its variants (call inside the parent's save) */
    public function syncFromParent(int $parentId, string $name, array $websiteIds, array $categoryIds): void
    {
        $attributes = $this->getAttributes($parentId);
        foreach ($this->getVariants($parentId) as $variant) {
            $model = (new Product())->load($variant['product_id']);
            $model->addData(['name' => mb_substr($name . ' - ' . implode(' / ', $this->labels($attributes, $variant['options'])), 0, 255)])->save();
            $model->getResource()->setWebsites($variant['product_id'], $websiteIds);
            (new Category())->getResource()->setProductCategories($variant['product_id'], $categoryIds);
        }
    }

    /** @return array<int, array<string, mixed>> the product entity's attributes */
    private function attributeRows(): array
    {
        $resource = (new Attribute())->getResource();

        return $resource->getByEntityType((int) $resource->getEntityTypes()[Product::ENTITY_TYPE]['entity_type_id']);
    }

    /** @return array<string, string> code => option value, one per variant attribute */
    private function options(array $attributes, array $given): array
    {
        $options = [];
        foreach ($attributes as $attribute) {
            $value = (string) ($given[$attribute['code']] ?? '');
            if (!in_array($value, array_column($attribute['options'], 'value'), true)) {
                throw new AttributeException('VARIANT_OPTION_INVALID', "Escolha uma opção válida de '{$attribute['label']}' para a variação.");
            }
            $options[$attribute['code']] = $value;
        }

        return $options;
    }

    /** @return string[] the option labels of a combination, in grid order */
    private function labels(array $attributes, array $options): array
    {
        return array_map(static function ($attribute) use ($options) {
            $labels = array_column($attribute['options'], 'label', 'value');

            return $labels[$options[$attribute['code']] ?? ''] ?? (string) ($options[$attribute['code']] ?? '');
        }, $attributes);
    }
}
