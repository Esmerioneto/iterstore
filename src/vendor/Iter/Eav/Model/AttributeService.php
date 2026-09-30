<?php

declare(strict_types=1);

namespace Iter\Eav\Model;

use Iter\Admin\Auth;
use Iter\Core\Log;
use Iter\Eav\InputTypeRegistry;

/**
 * Single entry point for attribute changes — the admin and module migrations
 * both go through here. storage_type comes from the input type and is_system
 * only from PHP callers, never from request data.
 *
 * Options ($data['options'], rows of value/label/sort_order/is_default) are
 * kept only for input types that support them; a missing key on update
 * leaves the current options untouched.
 */
class AttributeService
{
    /** fields an admin may still change on a module-provided (system) attribute */
    private const SYSTEM_EDITABLE = ['label', 'sort_order', 'is_visible'];

    /**
     * System attributes an entity's own form shows and validates (the customer's registration fields):
     * they never go into attribute sets, and only label and position change (not even visibility).
     */
    private const FIXED = ['customer' => ['email', 'firstname', 'lastname', 'customer_type', 'taxvat', 'dob', 'company_name', 'trade_name', 'state_registration', 'is_ie_exempt']];

    private const FLAGS = ['is_required', 'is_visible', 'is_searchable', 'is_filterable', 'is_sortable'];

    /** names an admin can't give an attribute: they are entity columns or technical */
    private const RESERVED = ['id', 'entity_id', 'website', 'website_id', 'grupo', 'group_id', 'ativo', 'is_active', 'senha', 'password',
        'password_hash', 'token', 'created_at', 'updated_at', 'attribute_set_id'];

    /**
     * @param array{entity_type: string, code: string, label: string, input_type: string, default_value?: ?string,
     *              is_required?: int, is_visible?: int, is_searchable?: int, is_filterable?: int, is_sortable?: int,
     *              sort_order?: int, is_variant?: int, options?: array<int, array>} $data
     */
    public function create(array $data, bool $system = false): Attribute
    {
        if (!$system) {
            self::assertCanManage();
        }
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
        $this->assertName((int) $entityType['entity_type_id'], $code, trim($data['label']), null, $system ? null : !empty($data['confirm_similar']));
        $options = $inputType['supports_options'] ? $this->options($data['options'] ?? [], $inputType) : [];

        $this->duplicateGuard(fn () => $model->getResource()->getConnection()->transaction(function () use ($model, $data, $entityType, $code, $inputType, $system, $options): void {
            $model->addData($this->fields($data, $inputType) + [
                'entity_type_id' => (int) $entityType['entity_type_id'],
                'code' => $code,
                'input_type' => $inputType['code'],
                'storage_type' => $inputType['storage_type'],
                'is_system' => $system ? 1 : 0,
            ])->save();
            if ($options) {
                $model->getResource()->replaceOptions((int) $model->getId(), $options);
            }
        }));
        Log::info("Atributo criado: {$data['entity_type']}/{$code}"); // Log adds the admin user

        return $model;
    }

    /**
     * Creating and removing fields is for the Administrador profile (Iter_Admin::root) only; editing
     * label, position and options stays with the "Atributos" resource.
     */
    /** @param array{entity_type_id: int|string, code: string} $attribute */
    public static function isFixed(array $attribute): bool
    {
        $types = array_column((new Attribute())->getResource()->getEntityTypes(), 'code', 'entity_type_id');

        return in_array($attribute['code'], self::FIXED[$types[$attribute['entity_type_id']] ?? ''] ?? [], true);
    }

    public static function canManage(): bool
    {
        // ponytail: the CLI (migrations, scripts) is trusted, whoever runs it owns the server
        return PHP_SAPI === 'cli' || Auth::isAllowed('Iter_Admin::root');
    }

    private static function assertCanManage(): void
    {
        if (!self::canManage()) {
            throw new AttributeException('FORBIDDEN', 'Só o perfil Administrador cria e remove atributos.');
        }
    }

    /** entity_type, code and is_system never change here; storage_type follows the input type while there are no values */
    public function update(int $id, array $data): Attribute
    {
        $model = $this->get($id);
        $this->assertLabel($data);
        $resource = $model->getResource();
        [$newKey, $oldKey] = $resource->nameKeys([trim($data['label']), (string) $model->getData('label')]);
        if ($newKey !== $oldKey) {
            $this->assertName((int) $model->getData('entity_type_id'), (string) $model->getData('code'), trim($data['label']), $id, $model->isSystem() ? null : !empty($data['confirm_similar']));
        }

        // empty = unchanged (the form disables the select on system attributes)
        $newType = ($data['input_type'] ?? '') ?: $model->getData('input_type');
        if ($newType !== $model->getData('input_type')) {
            if ($model->isSystem()) {
                throw new AttributeException('ATTRIBUTE_SYSTEM_PROTECTED', 'O tipo de um atributo do sistema não pode ser alterado.');
            }
            // stored values would sit in the wrong eav_value_* table, or text would be read as a file path
            $old = $this->inputType($model->getData('input_type'));
            $new = $this->inputType($newType);
            if (($new['storage_type'] !== $old['storage_type'] || (bool) $new['accept'] !== (bool) $old['accept'])
                && $model->getResource()->hasValues($id, $model->getData('storage_type'))) {
                throw new AttributeException('INPUT_TYPE_CHANGE_BLOCKED', 'O atributo já tem valores gravados e o novo tipo de campo guarda os valores de outro jeito; crie um novo atributo.');
            }
        }

        $inputType = $this->inputType($newType);
        $options = null; // null = leave as they are
        if (!$inputType['supports_options']) {
            $options = [];
        } elseif (array_key_exists('options', $data)) {
            $options = $this->options($data['options'], $inputType);
            if ($model->isSystem()) {
                $current = array_column($model->getResource()->getOptions($id), 'value');
                $missing = array_diff($current, array_column($options, 'value'));
                if ($missing) {
                    throw new AttributeException('ATTRIBUTE_SYSTEM_PROTECTED', 'Opções de um atributo do sistema não podem ser removidas nem ter o valor alterado: ' . implode(', ', $missing) . '.');
                }
            }
        }

        $fields = $this->fields($data, $inputType) + ['input_type' => $newType, 'storage_type' => $inputType['storage_type']];
        if ($model->isSystem()) {
            $fields = array_intersect_key($fields, array_flip(self::isFixed($model->getData()) ? ['label', 'sort_order'] : self::SYSTEM_EDITABLE));
        }
        // ponytail: switched on only while empty; turning it on later needs each entity's scope to build the keys
        if (($fields['is_unique'] ?? 0) && !$model->getData('is_unique') && $model->getResource()->hasValues($id, $model->getData('storage_type'))) {
            throw new AttributeException('UNIQUE_CHANGE_BLOCKED', "'Valor único' só pode ser ligado antes de o atributo ter valores gravados.");
        }

        try {
            $this->duplicateGuard(fn () => $model->getResource()->getConnection()->transaction(function () use ($model, $fields, $options, $id): void {
                $model->addData($fields)->save();
                if (!$model->getData('is_unique')) {
                    $model->getResource()->getConnection()->delete('eav_unique_value', ['attribute_id = ?' => $id]);
                }
                if ($options !== null) {
                    $model->getResource()->replaceOptions($id, $options);
                }
            }));
        } catch (\PDOException $e) {
            // other modules' tables (e.g. a product variant's combination) reference options with ON DELETE RESTRICT
            if ($e->getCode() === '23503') {
                $removed = array_diff(array_column($this->getOptions($id), 'value'), array_column($options ?? [], 'value'));
                throw new AttributeException('OPTION_IN_USE', 'Uma das opções removidas (' . implode(', ', $removed) . ') está em uso, por exemplo numa variação de produto, e não pode ser removida nem ter o valor alterado.');
            }
            throw $e;
        }

        return $model;
    }

    /** @return array<int, array{value: string, label: string, sort_order: int, is_default: int}> */
    public function getOptions(int $id): array
    {
        return (new Attribute())->getResource()->getOptions($id);
    }

    public function delete(int $id): void
    {
        self::assertCanManage();
        $model = $this->get($id);
        if ($model->isSystem()) {
            throw new AttributeException('ATTRIBUTE_SYSTEM_PROTECTED', 'Atributos do sistema não podem ser removidos.');
        }
        if ($model->getResource()->hasValues($id, $model->getData('storage_type'))) {
            throw new AttributeException('ATTRIBUTE_HAS_VALUES', 'O atributo tem valores gravados e não pode ser removido.');
        }
        $code = $model->getData('code');
        $model->delete();
        Log::info("Atributo removido: {$code} (id {$id})");
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

    /**
     * Normalizes posted option rows; fully blank rows (the form's spare ones) are skipped.
     *
     * @return array<int, array{value: string, label: string, sort_order: int, is_default: int}>
     */
    private function options(array $rows, array $inputType): array
    {
        $options = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $value = trim((string) ($row['value'] ?? ''));
            $label = trim((string) ($row['label'] ?? ''));
            if ($value === '' && $label === '') {
                continue;
            }
            if ($value === '') {
                throw new AttributeException('OPTION_VALUE_REQUIRED', "Informe o valor da opção '{$label}'.");
            }
            if (mb_strlen($value) > 255 || mb_strlen($label) > 255) {
                throw new AttributeException('OPTION_TOO_LONG', 'Valor e nome da opção têm no máximo 255 caracteres.');
            }
            if (isset($options[$value])) {
                throw new AttributeException('OPTION_DUPLICATE', "A opção '{$value}' aparece mais de uma vez.");
            }
            $sortOrder = trim((string) ($row['sort_order'] ?? ''));
            $options[$value] = [
                'value' => $value,
                'label' => $label === '' ? $value : $label,
                'sort_order' => $sortOrder === '' ? (count($options) + 1) * 10 : max(0, min(2147483647, (int) $sortOrder)),
                'is_default' => empty($row['is_default']) ? 0 : 1,
            ];
        }

        $seen = [];
        foreach ((new Attribute())->getResource()->nameKeys(array_column($options, 'label')) as $i => $key) {
            $label = array_values($options)[$i]['label'];
            if (isset($seen[$key])) {
                throw new AttributeException('OPTION_DUPLICATE', "As opções '{$seen[$key]}' e '{$label}' têm o mesmo nome (maiúsculas, acentos e plural não contam).");
            }
            $seen[$key] = $label;
        }

        if (!$inputType['multiple'] && array_sum(array_column($options, 'is_default')) > 1) {
            throw new AttributeException('OPTION_DEFAULT_MULTIPLE', "O tipo '{$inputType['label']}' aceita só uma opção padrão.");
        }

        return array_values($options);
    }

    /**
     * No other attribute of the entity may have the same normalized name (iter_attr_key: case, accents,
     * punctuation, connecting words, word order and regular plurals don't count) in its label or code;
     * the trigger eav_attribute_name_unique is the backstop. With $confirmSimilar false, a name one
     * letter away from another one (a typo) needs the admin's confirmation (confirm_similar); null = a
     * module's system attribute, which skips the reserved and similar checks.
     */
    private function assertName(int $entityTypeId, string $code, string $label, ?int $id, ?bool $confirmSimilar): void
    {
        $resource = (new Attribute())->getResource();
        $keys = $resource->nameKeys(array_merge([$code, $label], self::RESERVED));
        $mine = array_unique(array_splice($keys, 0, 2));
        if ($confirmSimilar !== null && array_intersect($mine, $keys)) {
            throw new AttributeException('ATTRIBUTE_RESERVED', "O nome '{$label}' (código '{$code}') é reservado pelo sistema.");
        }

        // ponytail: one typo; a larger distance flags every "x_a"/"x_b" pair
        $similar = [];
        foreach ($resource->getNameKeys($entityTypeId) as $row) {
            if ((int) $row['attribute_id'] === $id) {
                continue;
            }
            $theirs = [$row['code_key'], $row['label_key']];
            if (array_intersect($mine, $theirs)) {
                throw new AttributeException('ATTRIBUTE_DUPLICATE', "Já existe o atributo \"{$row['label']}\" ({$row['code']}) com esse nome (maiúsculas, acentos e plural não contam).");
            }
            // labels only: codes like x_p01/x_p02 differ by one letter on purpose
            $a = $mine[1] ?? $mine[0];
            if (min(strlen($a), strlen($row['label_key'])) >= 4 && levenshtein($a, $row['label_key']) === 1) {
                $similar[] = "\"{$row['label']}\" ({$row['code']})";
            }
        }
        if ($similar && $confirmSimilar === false) {
            throw new AttributeException('ATTRIBUTE_SIMILAR', 'O nome é parecido com ' . implode(', ', $similar) . '. Confira se não é o mesmo campo; se não for, marque "Salvar mesmo assim".');
        }
    }

    /** a concurrent save that got past assertName()/options() is stopped by the name triggers (23505) */
    private function duplicateGuard(callable $save): void
    {
        try {
            $save();
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505' && preg_match('/ERROR:\s+(.+)/', $e->getMessage(), $m)) {
                throw new AttributeException('ATTRIBUTE_DUPLICATE', trim($m[1]));
            }
            throw $e;
        }
    }

    private function assertLabel(array $data): void
    {
        if (trim((string) ($data['label'] ?? '')) === '') {
            throw new AttributeException('LABEL_REQUIRED', 'Nome é obrigatório.');
        }
    }

    /** editable columns, normalized */
    private function fields(array $data, array $inputType): array
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
        // "Usar na grade": one option per variant, so only a single-choice list
        $fields['is_variant'] = $inputType['code'] === 'select' && !empty($data['is_variant']) ? 1 : 0;
        // "Valor único": one plain text value (text, e-mail, CPF/CNPJ, phone...), compared without case
        $fields['is_unique'] = $inputType['storage_type'] === 'varchar' && !$inputType['supports_options'] && !$inputType['accept'] && !empty($data['is_unique']) ? 1 : 0;

        return $fields;
    }
}
