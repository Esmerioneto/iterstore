<?php

declare(strict_types=1);

namespace Iter\Catalog\Model;

use Iter\Core\Db\Connection;
use Iter\Eav\Form\FormSchemaGenerator;
use Iter\Eav\Model\Attribute;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\AttributeSet;
use Iter\Eav\Model\ValueService;
use Iter\Store\Model\Website;

/**
 * Products: sku/name/is_active live in catalog_product, websites and categories in
 * catalog_product_website and catalog_category_product (at least one of each, the categories
 * from the product's websites, and each website with one of them; on update only touched when $data has website_ids/category_ids),
 * everything else in EAV values. Only the attributes of the product's set are saved, and a set's
 * field missing from $attributes is saved empty (unchecked multiselect posts
 * nothing). Errors reuse AttributeException, as ValueService throws it too.
 *
 * A configurable product (type, chosen on creation) has no EAV values: its variants
 * (VariantService) have them, and get its name, websites and categories on each save.
 */
class ProductService
{
    /**
     * @param array{attribute_set_id: int, sku: string, name: string, is_active?: int, website_ids?: int[], category_ids?: int[], type?: string, variant_attributes?: string[]} $data
     * @param array<string, mixed> $attributes code => value
     */
    public function create(array $data, array $attributes = []): Product
    {
        $set = $this->set((int) ($data['attribute_set_id'] ?? 0));
        $fields = $this->fields($data);
        $configurable = ($data['type'] ?? 'simple') === 'configurable';
        $variantAttributeIds = $configurable ? (new VariantService())->attributeIds((string) $set->getData('code'), $data['variant_attributes'] ?? []) : [];
        $values = new ValueService();
        $rows = $configurable ? [] : $values->validate(Product::ENTITY_TYPE, $this->setValues($set, $attributes));
        $websiteIds = $this->websiteIds($data, true);
        $categoryIds = $this->categoryIds($data, true, $websiteIds);
        $this->checkCategoryPerWebsite($websiteIds, $categoryIds);
        $fields['url_key'] = $this->urlKey((string) ($data['url_key'] ?? ''), $fields, $websiteIds);

        // one transaction; values last, as write() deletes the files they replaced
        return Connection::get()->adapter()->transaction(function () use ($fields, $set, $values, $rows, $websiteIds, $categoryIds, $configurable, $variantAttributeIds): Product {
            $model = (new Product())->addData($fields + ['attribute_set_id' => (int) $set->getId(), 'type' => $configurable ? 'configurable' : 'simple'])->save();
            $model->getResource()->setWebsites((int) $model->getId(), $websiteIds);
            $model->getResource()->setVariantAttributes((int) $model->getId(), $variantAttributeIds);
            $this->saveCategories((int) $model->getId(), $categoryIds);
            $values->write((int) $model->getId(), $rows);

            return $model;
        });
    }

    /** the attribute set is chosen on creation and doesn't change */
    public function update(int $id, array $data, array $attributes = []): Product
    {
        $model = $this->get($id);
        $parentId = $model->getData('parent_id');
        if ($parentId !== null) { // a variant: name, websites and categories are the parent's; the combination is fixed
            $data = ['name' => $model->getData('name')] + array_diff_key($data, array_flip(['name', 'website_ids', 'category_ids']));
            $gridCodes = array_column((new VariantService())->getAttributes((int) $parentId), 'code');
            $attributes = array_intersect_key($this->getValues($id), array_flip($gridCodes)) + $attributes;
        }
        $configurable = $model->getData('type') === 'configurable';
        $fields = $this->fields($data, $id);
        $values = new ValueService();
        $rows = $configurable ? [] : $values->validate(Product::ENTITY_TYPE, $this->setValues($this->set((int) $model->getData('attribute_set_id')), $attributes), $id);
        $websiteIds = $this->websiteIds($data, false);
        $categoryIds = $this->categoryIds($data, false, $websiteIds ?? $this->getWebsiteIds($id));
        if ($categoryIds === null && $websiteIds !== null) {
            (new CategoryService())->validIds($this->getCategoryIds($id), $websiteIds); // the kept categories must fit the new websites
        }
        if ($websiteIds !== null || $categoryIds !== null) {
            $this->checkCategoryPerWebsite($websiteIds ?? $this->getWebsiteIds($id), $categoryIds ?? $this->getCategoryIds($id));
        }
        if ($parentId === null) { // a variant opens through its parent: no url_key; omitted = unchanged
            $fields['url_key'] = array_key_exists('url_key', $data)
                ? $this->urlKey((string) $data['url_key'], $fields, $websiteIds ?? $this->getWebsiteIds($id), $id)
                : $model->getData('url_key');
        }

        return Connection::get()->adapter()->transaction(function () use ($model, $fields, $values, $id, $rows, $websiteIds, $categoryIds, $configurable): Product {
            $model->addData($fields)->save();
            if ($websiteIds !== null) {
                $model->getResource()->setWebsites($id, $websiteIds);
            }
            $this->saveCategories($id, $categoryIds);
            $values->write($id, $rows);
            if ($configurable) {
                (new VariantService())->syncFromParent($id, $fields['name'], $this->getWebsiteIds($id), $this->getCategoryIds($id));
            }

            return $model;
        });
    }

    /** @return array<string, mixed> the product's EAV values, code => value */
    public function getValues(int $id): array
    {
        return (new ValueService())->load(Product::ENTITY_TYPE, $id);
    }

    /** @return int[] */
    public function getCategoryIds(int $id): array
    {
        return (new Category())->getResource()->getProductCategoryIds($id);
    }

    /** @return int[] */
    public function getWebsiteIds(int $id): array
    {
        return (new Product())->getResource()->getWebsiteIds($id);
    }

    /**
     * A new product needs a website; on update, omitted = unchanged, but never none
     *
     * @return ?int[] null = keep the current websites
     */
    private function websiteIds(array $data, bool $isNew): ?array
    {
        if (!$isNew && !array_key_exists('website_ids', $data)) {
            return null;
        }
        $ids = array_values(array_unique(array_map('intval', (array) ($data['website_ids'] ?? []))));
        $known = array_map('intval', array_column((new Website())->getList(), 'website_id'));
        if (!$ids || array_diff($ids, $known)) {
            throw new AttributeException('WEBSITE_INVALID', 'Escolha ao menos um website válido para o produto.');
        }

        return $ids;
    }

    /**
     * A new product needs a category of one of its websites; on update, omitted = unchanged, but never none.
     * The parents of a chosen subcategory are added.
     *
     * @param int[] $websiteIds the product's websites
     * @return ?int[] null = keep the current categories
     */
    private function categoryIds(array $data, bool $isNew, array $websiteIds): ?array
    {
        if (!$isNew && !array_key_exists('category_ids', $data)) {
            return null;
        }

        $ids = (new CategoryService())->validIds($data['category_ids'] ?? [], $websiteIds)
            ?: throw new AttributeException('CATEGORY_REQUIRED', 'Escolha ao menos uma categoria para o produto.');
        // a subcategory brings its parents (same website as it)
        $parents = array_column((new Category())->getResource()->getTree(), 'parent_id', 'category_id');
        foreach ($ids as $id) {
            for ($parent = $parents[$id]; $parent !== null; $parent = $parents[$parent]) {
                $ids[] = (int) $parent;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Every website of the product needs one of its categories, or the product
     * would be in a website where no category shows it.
     *
     * @param int[] $websiteIds
     * @param int[] $categoryIds already checked to be of these websites
     */
    private function checkCategoryPerWebsite(array $websiteIds, array $categoryIds): void
    {
        $categoryWebsites = array_column((new Category())->getResource()->getTree(), 'website_id', 'category_id');
        $covered = array_map(static fn ($id) => (int) $categoryWebsites[$id], $categoryIds);
        $missing = array_diff($websiteIds, $covered);
        if (!$missing) {
            return;
        }
        $names = array_column((new Website())->getList(), 'name', 'website_id');
        $hasCategories = array_intersect($missing, array_map('intval', $categoryWebsites));
        $list = "'" . implode("', '", array_map(static fn ($id) => $names[$id] ?? $id, $missing)) . "'";
        throw new AttributeException('CATEGORY_WEBSITE_MISSING', count($missing) > 1
            ? "O produto não tem categoria nos websites {$list}. Escolha uma categoria de cada um (ou cadastre em Catálogo → Categorias), ou desmarque esses websites."
            : "O produto não tem categoria no website {$list}. " . ($hasCategories
                ? 'Escolha uma categoria desse website ou desmarque o website.'
                : 'Esse website ainda não tem categorias: cadastre uma em Catálogo → Categorias ou desmarque o website.'));
    }

    private function saveCategories(int $id, ?array $categoryIds): void
    {
        if ($categoryIds !== null) {
            (new Category())->getResource()->setProductCategories($id, $categoryIds);
        }
    }

    public function delete(int $id): void
    {
        $model = $this->get($id);
        $variantIds = array_map('intval', array_column($model->getResource()->getVariantRows($id), 'product_id')); // rows go by FK CASCADE
        // values after the product: deleteEntity() deletes their files
        Connection::get()->adapter()->transaction(static function () use ($model, $id, $variantIds): void {
            $model->delete();
            foreach ([$id, ...$variantIds] as $entityId) {
                (new ValueService())->deleteEntity(Product::ENTITY_TYPE, $entityId);
            }
        });
    }

    public function get(int $id): Product
    {
        $model = (new Product())->load($id);
        if (!$model->getId()) {
            throw new AttributeException('PRODUCT_NOT_FOUND', 'Produto não encontrado.');
        }

        return $model;
    }

    private function set(int $setId): AttributeSet
    {
        $set = (new AttributeSet())->load($setId);
        $productType = (new Attribute())->getResource()->getEntityTypes()[Product::ENTITY_TYPE]['entity_type_id'] ?? null;
        if (!$set->getId() || (int) $set->getData('entity_type_id') !== (int) $productType) {
            throw new AttributeException('ATTRIBUTE_SET_NOT_FOUND', 'Conjunto de atributos inválido.');
        }

        return $set;
    }

    /** posted values limited to the fields the set's form shows, missing ones as empty */
    private function setValues(AttributeSet $set, array $attributes): array
    {
        $schema = (new FormSchemaGenerator())->generate(Product::ENTITY_TYPE, $set->getData('code'));
        $values = [];
        foreach ($schema['fields'] as $field) {
            $values[$field['name']] = $attributes[$field['name']] ?? '';
        }

        return $values;
    }

    /** format and uniqueness; $id = the product itself, when it already exists */
    public static function checkSku(string $sku, ?int $id = null): void
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $sku)) {
            throw new AttributeException('SKU_INVALID', 'SKU deve ter até 64 caracteres: letras, números, ponto, hífen e _ (começando por letra ou número).');
        }
        if ((new Product())->getResource()->skuExists($sku, $id)) {
            throw new AttributeException('SKU_DUPLICATE', "Já existe um produto com o SKU '{$sku}'.");
        }
    }

    /**
     * Empty = made from the name, plus the SKU when the name's is taken (two "Camiseta" are common); a key
     * typed in that is taken is an error. A category of one of the product's websites can't have it either,
     * or route(url) couldn't tell them apart.
     *
     * @param int[] $websiteIds
     */
    private function urlKey(string $urlKey, array $fields, array $websiteIds, ?int $id = null): string
    {
        $typed = trim($urlKey) !== '';
        $candidates = $typed ? [CategoryService::slug($urlKey)] : [CategoryService::slug($fields['name']), CategoryService::slug($fields['name'] . '-' . $fields['sku'])];
        foreach ($candidates as $i => $key) {
            if ($key === '' || strlen($key) > 255) {
                throw new AttributeException('URL_KEY_INVALID', 'Chave de URL inválida: use letras, números e hífen.');
            }
            $taken = match (true) {
                (new Product())->getResource()->urlKeyExists($key, $id) => "Já existe um produto com a chave de URL '{$key}'.",
                (bool) array_filter($websiteIds, static fn ($w) => (new Category())->getResource()->urlKeyExists($key, (int) $w)) => "Já existe uma categoria com a chave de URL '{$key}' num website do produto.",
                default => null,
            };
            if ($taken === null) {
                return $key;
            }
            if ($i === count($candidates) - 1) {
                throw new AttributeException('URL_KEY_DUPLICATE', $taken);
            }
        }
    }

    private function fields(array $data, ?int $id = null): array
    {
        $sku = trim((string) ($data['sku'] ?? ''));
        self::checkSku($sku, $id);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new AttributeException('NAME_REQUIRED', 'Nome é obrigatório (máx. 255 caracteres).');
        }

        return ['sku' => $sku, 'name' => $name, 'is_active' => empty($data['is_active']) ? 0 : 1];
    }
}
