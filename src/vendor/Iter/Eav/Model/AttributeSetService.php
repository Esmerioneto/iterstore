<?php

declare(strict_types=1);

namespace Iter\Eav\Model;

/**
 * Attribute sets pick which attributes of an entity type appear together,
 * in which group and order. Every entity type keeps exactly one default set.
 * Errors reuse AttributeException (same admin handling).
 */
class AttributeSetService
{
    /** @param array{entity_type: string, code: string, label: string, is_default?: int} $data */
    public function create(array $data): AttributeSet
    {
        $model = new AttributeSet();
        $entityType = (new Attribute())->getResource()->getEntityTypes()[$data['entity_type'] ?? ''] ?? null;
        if ($entityType === null) {
            throw new AttributeException('ENTITY_TYPE_INVALID', 'Entidade inválida.');
        }
        $code = $data['code'] ?? '';
        if (!preg_match('/^[a-z][a-z0-9_]{0,99}$/', $code)) {
            throw new AttributeException('CODE_INVALID', 'Código deve começar com letra e conter apenas a-z, 0-9 e _ (máx. 100).');
        }
        $label = $this->label($data);
        $entityTypeId = (int) $entityType['entity_type_id'];
        if ($model->getResource()->codeExists($entityTypeId, $code)) {
            throw new AttributeException('CODE_DUPLICATE', "Já existe um conjunto '{$code}' nesta entidade.");
        }

        $isDefault = empty($data['is_default']) ? 0 : 1;
        if ($isDefault) {
            $model->getResource()->clearDefault($entityTypeId);
        }

        return $model->addData([
            'entity_type_id' => $entityTypeId,
            'code' => $code,
            'label' => $label,
            'is_default' => $isDefault,
        ])->save();
    }

    /**
     * entity_type and code never change. The default flag can only be moved by
     * marking another set, never just cleared.
     *
     * @param array{label: string, is_default?: int, items?: array<int|string, array>} $data
     *        items: attribute_id => {in: 1, group: string, sort_order: string}; rows without "in" are left out
     */
    public function update(int $id, array $data): AttributeSet
    {
        $model = $this->get($id);
        $label = $this->label($data);
        $items = array_key_exists('items', $data) ? $this->items($data['items'], (int) $model->getData('entity_type_id')) : null;

        $becomesDefault = !$model->isDefault() && !empty($data['is_default']);
        if ($becomesDefault) {
            $model->getResource()->clearDefault((int) $model->getData('entity_type_id'));
        }

        $model->addData(['label' => $label, 'is_default' => $model->isDefault() || $becomesDefault ? 1 : 0])->save();
        if ($items !== null) {
            $model->getResource()->replaceItems($id, $items);
        }

        return $model;
    }

    public function delete(int $id): void
    {
        $model = $this->get($id);
        if ($model->isDefault()) {
            throw new AttributeException('ATTRIBUTE_SET_DEFAULT', 'O conjunto padrão não pode ser removido; marque outro como padrão antes.');
        }
        try {
            $model->delete();
        } catch (\PDOException $e) {
            // entity tables (catalog_product...) reference their set with ON DELETE RESTRICT
            if ($e->getCode() === '23503') {
                throw new AttributeException('ATTRIBUTE_SET_IN_USE', 'O conjunto tem registros (ex.: produtos) e não pode ser removido.');
            }
            throw $e;
        }
    }

    public function get(int $id): AttributeSet
    {
        $model = (new AttributeSet())->load($id);
        if (!$model->getId()) {
            throw new AttributeException('ATTRIBUTE_SET_NOT_FOUND', 'Conjunto não encontrado.');
        }

        return $model;
    }

    /** @return array<int, array<string, mixed>> see ResourceModel\AttributeSet::getItems() */
    public function getItems(int $id): array
    {
        return (new AttributeSet())->getResource()->getItems($id);
    }

    private function label(array $data): string
    {
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            throw new AttributeException('LABEL_REQUIRED', 'Nome é obrigatório.');
        }

        return $label;
    }

    /** @return array<int, array{attribute_id: int, group_name: ?string, sort_order: int}> */
    private function items(array $rows, int $entityTypeId): array
    {
        $allowed = array_column((new Attribute())->getResource()->getByEntityType($entityTypeId), null, 'attribute_id');

        $items = [];
        foreach ($rows as $attributeId => $row) {
            if (!is_array($row) || empty($row['in'])) {
                continue;
            }
            if (!isset($allowed[$attributeId])) {
                throw new AttributeException('ATTRIBUTE_NOT_FOUND', 'Atributo não pertence à entidade deste conjunto.');
            }
            if (AttributeService::isFixed($allowed[$attributeId])) {
                throw new AttributeException('ATTRIBUTE_FIXED', "'{$allowed[$attributeId]['label']}' já tem lugar fixo no formulário e não entra em conjuntos.");
            }
            $group = trim((string) ($row['group'] ?? ''));
            if (mb_strlen($group) > 100) {
                throw new AttributeException('GROUP_TOO_LONG', 'O nome do grupo tem no máximo 100 caracteres.');
            }
            $sortOrder = trim((string) ($row['sort_order'] ?? ''));
            $items[] = [
                'attribute_id' => (int) $attributeId,
                'group_name' => $group === '' ? null : $group,
                'sort_order' => $sortOrder === '' ? (count($items) + 1) * 10 : max(0, min(2147483647, (int) $sortOrder)),
            ];
        }

        return $items;
    }
}
