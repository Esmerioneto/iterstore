<?php

declare(strict_types=1);

namespace Iter\Catalog\Model;

use Iter\Eav\Form\FormSchemaGenerator;
use Iter\Eav\Model\Attribute;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\AttributeSet;
use Iter\Eav\Model\ValueService;

/**
 * Products: sku/name/is_active live in catalog_product, everything else in
 * EAV values. Only the attributes of the product's set are saved, and a set's
 * field missing from $attributes is saved empty (unchecked multiselect posts
 * nothing). Errors reuse AttributeException, as ValueService throws it too.
 */
class ProductService
{
    /**
     * @param array{attribute_set_id: int, sku: string, name: string, is_active?: int} $data
     * @param array<string, mixed> $attributes code => value
     */
    public function create(array $data, array $attributes = []): Product
    {
        $set = $this->set((int) ($data['attribute_set_id'] ?? 0));
        $fields = $this->fields($data);
        $rows = (new ValueService())->validate(Product::ENTITY_TYPE, $this->setValues($set, $attributes));

        // ponytail: product and values are saved on separate connections (Connection opens a PDO
        // per call), so everything is validated first; a DB failure in between leaves a product
        // without values, fixable by saving it again
        $model = (new Product())->addData($fields + ['attribute_set_id' => (int) $set->getId()])->save();
        (new Attribute())->getResource()->saveValues((int) $model->getId(), $rows);

        return $model;
    }

    /** the attribute set is chosen on creation and doesn't change */
    public function update(int $id, array $data, array $attributes = []): Product
    {
        $model = $this->get($id);
        $fields = $this->fields($data, $id);
        $rows = (new ValueService())->validate(Product::ENTITY_TYPE, $this->setValues($this->set((int) $model->getData('attribute_set_id')), $attributes));

        $model->addData($fields)->save();
        (new Attribute())->getResource()->saveValues($id, $rows);

        return $model;
    }

    /** @return array<string, mixed> the product's EAV values, code => value */
    public function getValues(int $id): array
    {
        return (new ValueService())->load(Product::ENTITY_TYPE, $id);
    }

    public function delete(int $id): void
    {
        $model = $this->get($id);
        $model->delete();
        // after the product: if this fails, orphan values are harmless and the product is gone
        (new ValueService())->deleteEntity(Product::ENTITY_TYPE, $id);
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

    private function fields(array $data, ?int $id = null): array
    {
        $sku = trim((string) ($data['sku'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $sku)) {
            throw new AttributeException('SKU_INVALID', 'SKU deve ter até 64 caracteres: letras, números, ponto, hífen e _ (começando por letra ou número).');
        }
        if ((new Product())->getResource()->skuExists($sku, $id)) {
            throw new AttributeException('SKU_DUPLICATE', "Já existe um produto com o SKU '{$sku}'.");
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new AttributeException('NAME_REQUIRED', 'Nome é obrigatório (máx. 255 caracteres).');
        }

        return ['sku' => $sku, 'name' => $name, 'is_active' => empty($data['is_active']) ? 0 : 1];
    }
}
