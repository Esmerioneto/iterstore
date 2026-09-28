<?php

declare(strict_types=1);

namespace Iter\Eav\Model;

use Iter\Eav\InputTypeRegistry;

/**
 * Single entry point for attribute changes — the admin and module migrations
 * both go through here. storage_type comes from the input type and is_system
 * only from PHP callers, never from request data.
 */
class AttributeService
{
    /** fields an admin may still change on a module-provided (system) attribute */
    private const SYSTEM_EDITABLE = ['label', 'sort_order', 'is_visible'];

    private const FLAGS = ['is_required', 'is_visible', 'is_searchable', 'is_filterable', 'is_sortable'];

    /**
     * @param array{entity_type: string, code: string, label: string, input_type: string, default_value?: ?string,
     *              is_required?: int, is_visible?: int, is_searchable?: int, is_filterable?: int, is_sortable?: int,
     *              sort_order?: int} $data
     */
    public function create(array $data, bool $system = false): Attribute
    {
        $model = new Attribute();
        $entityType = $model->getResource()->getEntityTypes()[$data['entity_type'] ?? ''] ?? null;
        if ($entityType === null) {
            throw new AttributeException('ENTITY_TYPE_INVALID', 'Entidade inválida.');
        }
        $code = $data['code'] ?? '';
        if (!preg_match('/^[a-z][a-z0-9_]{0,99}$/', $code)) {
            throw new AttributeException('CODE_INVALID', 'Código deve começar com letra e conter apenas a-z, 0-9 e _ (máx. 100).');
        }
        $this->assertLabel($data);
        $inputType = $this->inputType($data['input_type'] ?? '');
        if ($model->getResource()->codeExists((int) $entityType['entity_type_id'], $code)) {
            throw new AttributeException('CODE_DUPLICATE', "Já existe um atributo '{$code}' nesta entidade.");
        }

        return $model->addData($this->fields($data) + [
            'entity_type_id' => (int) $entityType['entity_type_id'],
            'code' => $code,
            'input_type' => $inputType['code'],
            'storage_type' => $inputType['storage_type'],
            'is_system' => $system ? 1 : 0,
        ])->save();
    }

    /** entity_type, code, storage_type and is_system never change here */
    public function update(int $id, array $data): Attribute
    {
        $model = $this->get($id);
        $this->assertLabel($data);

        // empty = unchanged (the form disables the select on system attributes)
        $newType = ($data['input_type'] ?? '') ?: $model->getData('input_type');
        if ($newType !== $model->getData('input_type')) {
            if ($model->isSystem()) {
                throw new AttributeException('ATTRIBUTE_SYSTEM_PROTECTED', 'O tipo de um atributo do sistema não pode ser alterado.');
            }
            // ponytail: blocks every storage change since value tables don't exist yet;
            // relax to "only when the attribute has values" once eav_value_* lands
            if ($this->inputType($newType)['storage_type'] !== $model->getData('storage_type')) {
                throw new AttributeException('INPUT_TYPE_CHANGE_BLOCKED', 'O novo tipo de campo usa outro formato de armazenamento; crie um novo atributo.');
            }
        }

        $fields = $this->fields($data) + ['input_type' => $newType];
        if ($model->isSystem()) {
            $fields = array_intersect_key($fields, array_flip(self::SYSTEM_EDITABLE));
        }

        return $model->addData($fields)->save();
    }

    public function delete(int $id): void
    {
        $model = $this->get($id);
        if ($model->isSystem()) {
            throw new AttributeException('ATTRIBUTE_SYSTEM_PROTECTED', 'Atributos do sistema não podem ser removidos.');
        }
        // TODO(eav_value_*): refuse with ATTRIBUTE_HAS_VALUES once values are stored
        $model->delete();
    }

    public function get(int $id): Attribute
    {
        $model = (new Attribute())->load($id);
        if (!$model->getId()) {
            throw new AttributeException('ATTRIBUTE_NOT_FOUND', 'Atributo não encontrado.');
        }

        return $model;
    }

    private function inputType(string $code): array
    {
        if (!InputTypeRegistry::has($code)) {
            throw new AttributeException('INPUT_TYPE_INVALID', "O tipo de campo '{$code}' não está registrado.");
        }

        return InputTypeRegistry::get($code);
    }

    private function assertLabel(array $data): void
    {
        if (trim((string) ($data['label'] ?? '')) === '') {
            throw new AttributeException('LABEL_REQUIRED', 'Nome é obrigatório.');
        }
    }

    /** editable columns, normalized */
    private function fields(array $data): array
    {
        $fields = [
            'label' => trim((string) $data['label']),
            'default_value' => ($data['default_value'] ?? '') === '' ? null : (string) $data['default_value'],
            'sort_order' => max(0, min(2147483647, (int) ($data['sort_order'] ?? 0))),
        ];
        foreach (self::FLAGS as $flag) {
            // missing flag = off, except is_visible, which defaults to on
            $fields[$flag] = empty($data[$flag] ?? ($flag === 'is_visible')) ? 0 : 1;
        }

        return $fields;
    }
}
