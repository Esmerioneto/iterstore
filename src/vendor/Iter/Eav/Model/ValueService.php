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
 *
 * File types (input type with "accept", e.g. image) store the file under
 * public/media/eav/ and keep its relative path as the value. Their value is
 * ['path' => tmp file, 'name' => original name] to replace, ['remove' => 1]
 * to delete, anything else to keep the current file — a posted string is
 * never taken as a path. fromRequest() builds this from $_POST + $_FILES.
 */
class ValueService
{
    public const MEDIA_URL = '/media/eav/';

    public static function mediaDir(): string
    {
        return \getPath('public') . 'media' . DIRECTORY_SEPARATOR . 'eav' . DIRECTORY_SEPARATOR;
    }

    /**
     * $_POST[$field] with the uploads of $_FILES[$field] (PHP's per-key layout)
     * merged in as ['path', 'name']. Throws on a failed upload.
     */
    public static function fromRequest(string $field = 'attributes'): array
    {
        $values = isset($_POST[$field]) && is_array($_POST[$field]) ? $_POST[$field] : [];
        $files = $_FILES[$field] ?? null;
        foreach (is_array($files['error'] ?? null) ? $files['error'] : [] as $code => $error) {
            if ($error === UPLOAD_ERR_NO_FILE || !is_int($error)) {
                continue;
            }
            $name = (string) $files['name'][$code];
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                throw new AttributeException('FILE_TOO_LARGE', "'{$name}' é maior que o limite de " . ini_get('upload_max_filesize') . '.');
            }
            if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($files['tmp_name'][$code])) {
                throw new AttributeException('FILE_UPLOAD_FAILED', "Falha ao enviar '{$name}'. Tente de novo.");
            }
            $values[$code] = ['path' => $files['tmp_name'][$code], 'name' => $name];
        }

        return $values;
    }

    /** @return array<string, string|int|bool|array> code => value (multiple types give arrays) */
    public function load(string $entityType, int $entityId): array
    {
        $resource = (new Attribute())->getResource();
        $values = [];
        foreach ($resource->getValues($this->entityTypeId($entityType), $entityId) as $row) {
            $value = $row['value'];
            $values[$row['code']] = match (true) {
                $this->type($row)['multiple'] => json_decode($value, true) ?? [],
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
        $this->write($entityId, $this->validate($entityType, $values, $entityId));
    }

    /**
     * Writes what validate() returned: new files first (a value never points to a
     * missing file), then the values, then the files they replaced.
     */
    public function write(int $entityId, array $rows): void
    {
        $copied = [];
        try {
            foreach ($rows as $row) {
                if (isset($row['upload'])) {
                    $target = self::mediaDir() . $row['value'];
                    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
                        throw new \RuntimeException('Não foi possível criar a pasta ' . dirname($target));
                    }
                    if (!copy($row['upload'], $target)) {
                        throw new \RuntimeException("Não foi possível gravar {$target}");
                    }
                    $copied[] = $target;
                }
            }
            (new Attribute())->getResource()->saveValues($entityId, $rows);
        } catch (\Throwable $e) {
            array_map('unlink', $copied);
            throw $e;
        }
        foreach ($rows as $row) {
            if (($row['old_file'] ?? null) !== null) {
                @unlink(self::mediaDir() . $row['old_file']);
            }
        }
    }

    /**
     * Throws on the first invalid value. Callers that write their own row first
     * (a new product) call this before, so a bad value doesn't leave half a record.
     *
     * $entityId (null for a record not saved yet) is needed to know the current files.
     *
     * @return array<int, array{attribute_id: int, storage_type: string, value: string|int|null, upload?: string, old_file?: ?string}>
     */
    public function validate(string $entityType, array $values, ?int $entityId = null): array
    {
        $attributes = array_column((new Attribute())->getResource()->getByEntityType($this->entityTypeId($entityType)), null, 'code');
        $current = null;

        $rows = [];
        foreach ($values as $code => $value) {
            $attribute = $attributes[$code] ?? throw new AttributeException('ATTRIBUTE_NOT_FOUND', "O atributo '{$code}' não existe em '{$entityType}'.");
            $accept = $this->type($attribute)['accept'];
            if ($accept) {
                $current ??= $entityId === null ? [] : $this->load($entityType, $entityId);
                $row = $this->file($attribute, $accept, $value, $current[$code] ?? null, $entityType);
                if ($row !== null) {
                    $rows[] = $row;
                }
                continue;
            }
            $rows[] = [
                'attribute_id' => (int) $attribute['attribute_id'],
                'storage_type' => $attribute['storage_type'],
                'value' => $this->normalize($attribute, $value),
            ];
        }

        return $rows;
    }

    /** call when the entity itself is deleted: value tables have no FK to entities */
    public function deleteEntity(string $entityType, int $entityId): void
    {
        $resource = (new Attribute())->getResource();
        $files = array_filter($resource->getValues($this->entityTypeId($entityType), $entityId), fn ($row) => (bool) $this->type($row)['accept']);
        $resource->deleteValues($this->entityTypeId($entityType), $entityId);
        foreach ($files as $row) {
            @unlink(self::mediaDir() . $row['value']);
        }
    }

    /** @return ?array the row to write, null = keep the current file */
    private function file(array $attribute, array $accept, mixed $value, ?string $current, string $entityType): ?array
    {
        $label = $attribute['label'];
        $row = ['attribute_id' => (int) $attribute['attribute_id'], 'storage_type' => $attribute['storage_type'], 'old_file' => $current];

        if (is_array($value) && isset($value['path'])) {
            $mime = is_file((string) $value['path']) ? mime_content_type((string) $value['path']) : false;
            $ext = $accept[$mime] ?? throw new AttributeException('FILE_TYPE_INVALID', sprintf(
                "'%s' não é um arquivo aceito em '%s' (%s).",
                $value['name'] ?? '',
                $label,
                strtoupper(implode(', ', array_unique($accept)))
            ));
            // random name in a 256-way subfolder: the upload never picks its own path or extension
            return $row + ['value' => $entityType . '/' . bin2hex(random_bytes(1)) . '/' . bin2hex(random_bytes(16)) . '.' . $ext, 'upload' => (string) $value['path']];
        }
        if (is_array($value) && !empty($value['remove']) && $current !== null) {
            return $attribute['is_required']
                ? throw new AttributeException('VALUE_REQUIRED', "'{$label}' é obrigatório; envie outro arquivo em vez de remover.")
                : $row + ['value' => null];
        }
        if ($current === null && $attribute['is_required']) {
            throw new AttributeException('VALUE_REQUIRED', "'{$label}' é obrigatório.");
        }

        return null;
    }

    private function type(array $attribute): array
    {
        return InputTypeRegistry::has($attribute['input_type'])
            ? InputTypeRegistry::get($attribute['input_type'])
            : ['supports_options' => false, 'multiple' => false, 'accept' => []];
    }

    /** @return string|int|null what goes in the value column; null = no value */
    private function normalize(array $attribute, mixed $value): string|int|null
    {
        $label = $attribute['label'];
        $type = $this->type($attribute);

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
