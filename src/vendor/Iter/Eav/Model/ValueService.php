<?php

declare(strict_types=1);

namespace Iter\Eav\Model;

use Iter\Eav\InputTypeRegistry;

/**
 * Reads and writes an entity's attribute values (eav_value_*). Values are
 * keyed by attribute code, the same names FormSchemaGenerator gives the fields,
 * so a posted form goes straight into save().
 *
 * save() only touches the codes it receives; an empty value ('' / null / [])
 * deletes it. All values are validated before anything is written, then saved
 * in one transaction.
 */
class ValueService
{
    /** @return array<string, string|int|bool|array> code => value (multiple types give arrays) */
    public function load(string $entityType, int $entityId): array
    {
        $resource = (new Attribute())->getResource();
        $values = [];
        foreach ($resource->getValues($this->entityTypeId($entityType), $entityId) as $row) {
            $value = $row['value'];
            $multiple = InputTypeRegistry::has($row['input_type']) && InputTypeRegistry::get($row['input_type'])['multiple'];
            $values[$row['code']] = match (true) {
                $multiple => json_decode($value, true) ?? [],
                $row['storage_type'] === 'int' => (int) $value,
                $row['storage_type'] === 'boolean' => $value === '1',
                $row['storage_type'] === 'decimal' => rtrim(rtrim($value, '0'), '.'),
                default => $value,
            };
        }

        return $values;
    }

    /** @param array<string, mixed> $values code => value */
    public function save(string $entityType, int $entityId, array $values): void
    {
        $resource = (new Attribute())->getResource();
        $attributes = array_column($resource->getByEntityType($this->entityTypeId($entityType)), null, 'code');

        $rows = [];
        foreach ($values as $code => $value) {
            $attribute = $attributes[$code] ?? throw new AttributeException('ATTRIBUTE_NOT_FOUND', "O atributo '{$code}' não existe em '{$entityType}'.");
            $rows[] = [
                'attribute_id' => (int) $attribute['attribute_id'],
                'storage_type' => $attribute['storage_type'],
                'value' => $this->normalize($attribute, $value),
            ];
        }
        $resource->saveValues($entityId, $rows);
    }

    /** call when the entity itself is deleted: value tables have no FK to entities */
    public function deleteEntity(string $entityType, int $entityId): void
    {
        (new Attribute())->getResource()->deleteValues($this->entityTypeId($entityType), $entityId);
    }

    /** @return string|int|null what goes in the value column; null = no value */
    private function normalize(array $attribute, mixed $value): string|int|null
    {
        $label = $attribute['label'];
        $type = InputTypeRegistry::has($attribute['input_type'])
            ? InputTypeRegistry::get($attribute['input_type'])
            : ['supports_options' => false, 'multiple' => false];

        if ($type['multiple']) {
            $value = array_values(array_unique(array_filter(array_map('trim', array_map('strval', (array) $value)), 'strlen')));
        } elseif (is_array($value)) {
            throw new AttributeException('VALUE_INVALID', "Valor inválido para '{$label}'.");
        } else {
            $value = trim((string) $value);
        }

        if ($value === '' || $value === []) {
            if ($attribute['is_required']) {
                throw new AttributeException('VALUE_REQUIRED', "'{$label}' é obrigatório.");
            }
            return null;
        }

        if ($type['supports_options']) {
            $allowed = array_column((new Attribute())->getResource()->getOptions((int) $attribute['attribute_id']), 'value');
            $unknown = array_diff((array) $value, $allowed);
            if ($unknown) {
                throw new AttributeException('VALUE_INVALID', "Opção inválida para '{$label}': " . implode(', ', $unknown) . '.');
            }
        }
        if ($type['multiple']) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $invalid = new AttributeException('VALUE_INVALID', "Valor inválido para '{$label}'.");
        switch ($attribute['storage_type']) {
            case 'int':
                return filter_var($value, FILTER_VALIDATE_INT) === false ? throw $invalid : (int) $value;
            case 'decimal':
                $value = str_replace(',', '.', $value); // pt-BR "12,5"
                return preg_match('/^-?\d{1,14}(\.\d+)?$/', $value) ? $value : throw $invalid;
            case 'boolean':
                return in_array($value, ['0', '1'], true) ? (int) $value : throw $invalid;
            case 'datetime':
                // date and datetime-local inputs: 2026-09-28 / 2026-09-28T14:30
                if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $value, $m)
                    || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || ($m[4] ?? 0) > 23 || ($m[5] ?? 0) > 59 || ($m[6] ?? 0) > 59) {
                    throw $invalid;
                }
                return str_replace('T', ' ', $value);
            case 'varchar':
                return mb_strlen($value) > 255 ? throw new AttributeException('VALUE_TOO_LONG', "'{$label}' tem no máximo 255 caracteres.") : $value;
            default:
                return $value;
        }
    }

    private function entityTypeId(string $entityType): int
    {
        $row = (new Attribute())->getResource()->getEntityTypes()[$entityType]
            ?? throw new AttributeException('ENTITY_TYPE_INVALID', 'Entidade inválida.');

        return (int) $row['entity_type_id'];
    }
}
