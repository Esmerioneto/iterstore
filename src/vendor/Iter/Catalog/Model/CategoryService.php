<?php

declare(strict_types=1);

namespace Iter\Catalog\Model;

use Iter\Eav\Form\FormSchemaGenerator;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\ValueService;
use Iter\Store\Model\Website;

/**
 * Categories: a tree of name/url_key/is_active/position rows plus EAV values from
 * the category entity's default set (same rules as products: a set field missing
 * from $attributes is saved empty). A category with children isn't deleted, and
 * can't be moved under itself or its own children. The website is chosen on
 * creation and doesn't change; a subcategory is in its parent's website.
 */
class CategoryService
{
    /**
     * @param array{website_id?: int, parent_id?: ?int, name: string, url_key?: string, is_active?: int, position?: int} $data
     * @param array<string, mixed> $attributes code => value
     */
    public function create(array $data, array $attributes = []): Category
    {
        $fields = $this->fields($data);
        $values = new ValueService();
        $rows = $values->validate(Category::ENTITY_TYPE, $this->setValues($attributes));

        // ponytail: row and values on separate connections, as in ProductService
        $model = (new Category())->addData($fields)->save();
        $values->write((int) $model->getId(), $rows);

        return $model;
    }

    public function update(int $id, array $data, array $attributes = []): Category
    {
        $model = $this->get($id);
        $fields = $this->fields($data, $id);
        $values = new ValueService();
        $rows = $values->validate(Category::ENTITY_TYPE, $this->setValues($attributes), $id);

        $model->addData($fields)->save();
        $values->write($id, $rows);

        return $model;
    }

    /** products stay, only unlinked (FK CASCADE) */
    public function delete(int $id): void
    {
        $model = $this->get($id);
        if ($model->getResource()->hasChildren($id)) {
            throw new AttributeException('CATEGORY_HAS_CHILDREN', "A categoria '{$model->getData('name')}' tem subcategorias. Remova ou mova as subcategorias antes.");
        }
        if ($model->getResource()->hasProducts($id)) {
            throw new AttributeException('CATEGORY_HAS_PRODUCTS', "A categoria '{$model->getData('name')}' tem produtos. Mova os produtos para outra categoria antes.");
        }
        $model->delete();
        (new ValueService())->deleteEntity(Category::ENTITY_TYPE, $id);
    }

    public function get(int $id): Category
    {
        $model = (new Category())->load($id);
        if (!$model->getId()) {
            throw new AttributeException('CATEGORY_NOT_FOUND', 'Categoria não encontrada.');
        }

        return $model;
    }

    /** @return array<string, mixed> the category's EAV values, code => value */
    public function getValues(int $id): array
    {
        return (new ValueService())->load(Category::ENTITY_TYPE, $id);
    }

    /**
     * @param mixed $ids posted/API ids
     * @param ?int[] $websiteIds when given, every category must be in one of them
     * @return int[] existing category ids
     */
    public function validIds(mixed $ids, ?array $websiteIds = null): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter((array) $ids, static fn ($v) => $v !== '' && $v !== null))));
        $known = array_column((new Category())->getResource()->getTree(), null, 'category_id');
        $unknown = array_diff($ids, array_keys($known));
        if ($unknown) {
            throw new AttributeException('CATEGORY_NOT_FOUND', 'Categoria não encontrada: ' . implode(', ', $unknown) . '.');
        }
        foreach ($websiteIds === null ? [] : $ids as $id) {
            if (!in_array((int) $known[$id]['website_id'], $websiteIds, true)) {
                throw new AttributeException('CATEGORY_WEBSITE_MISMATCH', "A categoria '{$known[$id]['name']}' é de um website em que o produto não está.");
            }
        }

        return $ids;
    }

    /** "Higiene & Cosméticos" -> "higiene-cosmeticos" */
    public static function slug(string $text): string
    {
        $ascii = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
    }

    private function setValues(array $attributes): array
    {
        $values = [];
        foreach ((new FormSchemaGenerator())->generate(Category::ENTITY_TYPE)['fields'] as $field) {
            $values[$field['name']] = $attributes[$field['name']] ?? '';
        }

        return $values;
    }

    private function fields(array $data, ?int $id = null): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new AttributeException('NAME_REQUIRED', 'Nome é obrigatório (máx. 255 caracteres).');
        }
        // empty = made from the name
        $urlKey = self::slug(trim((string) ($data['url_key'] ?? '')) ?: $name);
        if ($urlKey === '' || strlen($urlKey) > 255) {
            throw new AttributeException('URL_KEY_INVALID', 'Chave de URL inválida: use letras, números e hífen.');
        }
        $resource = (new Category())->getResource();
        if ($resource->urlKeyExists($urlKey, $id)) {
            throw new AttributeException('URL_KEY_DUPLICATE', "Já existe uma categoria com a chave de URL '{$urlKey}'.");
        }
        $parentId = empty($data['parent_id']) ? null : (int) $data['parent_id'];
        $websiteId = $id !== null ? (int) $this->get($id)->getData('website_id') : null; // fixed once created
        if ($parentId !== null) {
            $parentWebsite = (int) $this->get($parentId)->getData('website_id'); // CATEGORY_NOT_FOUND
            if ($id !== null && in_array($parentId, $resource->getSubtreeIds($id), true)) {
                throw new AttributeException('CATEGORY_PARENT_INVALID', 'Uma categoria não pode ficar dentro dela mesma nem de uma subcategoria sua.');
            }
            if ($websiteId !== null && $parentWebsite !== $websiteId) {
                throw new AttributeException('CATEGORY_WEBSITE_MISMATCH', 'A categoria pai é de outro website.');
            }
            $websiteId = $parentWebsite;
        }
        $websiteId ??= (int) ($data['website_id'] ?? 0);
        if ($websiteId <= 0 || !(new Website())->load($websiteId)->getId()) {
            throw new AttributeException('WEBSITE_INVALID', 'Selecione um website.');
        }

        return [
            'website_id' => $websiteId,
            'parent_id' => $parentId,
            'name' => $name,
            'url_key' => $urlKey,
            'is_active' => empty($data['is_active']) ? 0 : 1,
            'position' => max(0, min(2147483647, (int) ($data['position'] ?? 0))), // >= 0: getTree() sorts it as text
        ];
    }
}
