<?php

declare(strict_types=1);

namespace Iter\Eav;

/**
 * Field types an EAV attribute can use. Modules declare theirs in
 * etc/input_types.json ({code, label, storage_type, supports_options?, multiple?, accept?});
 * "accept" (mime => extension) makes it a file upload field: the value is the
 * stored file's path, see ValueService.
 * EAV asks for capabilities here instead of testing codes like "select".
 */
class InputTypeRegistry
{
    public const STORAGE_TYPES = ['varchar', 'text', 'int', 'decimal', 'datetime', 'boolean'];

    /** @var array<string, array>|null */
    private static ?array $types = null;

    /** @return array<string, array> code => {code, label, storage_type, supports_options, multiple, accept} */
    public static function all(): array
    {
        return self::$types ??= self::build(\getModuleJson('etc' . DIRECTORY_SEPARATOR . 'input_types.json'));
    }

    public static function has(string $code): bool
    {
        return isset(self::all()[$code]);
    }

    public static function get(string $code): array
    {
        return self::all()[$code] ?? throw new \InvalidArgumentException("O tipo de campo '{$code}' não está registrado.");
    }

    /**
     * Public so the selftest can feed it lists directly.
     *
     * @return array<string, array>
     */
    public static function build(array $list): array
    {
        $types = [];
        foreach ($list as $type) {
            $code = $type['code'] ?? '';
            if (isset($types[$code])) {
                throw new \RuntimeException("Tipo de campo '{$code}' registrado mais de uma vez.");
            }
            if (!in_array($type['storage_type'] ?? null, self::STORAGE_TYPES, true)) {
                throw new \RuntimeException("Tipo de campo '{$code}' com storage_type inválido.");
            }
            $accept = $type['accept'] ?? [];
            foreach ($accept as $mime => $ext) {
                if (!is_string($mime) || !preg_match('/^[a-z0-9]{1,8}$/', (string) $ext)) {
                    throw new \RuntimeException("Tipo de campo '{$code}' com accept inválido (mime => extensão).");
                }
            }
            $types[$code] = [
                'code' => $code,
                'label' => $type['label'] ?? $code,
                'storage_type' => $type['storage_type'],
                'supports_options' => (bool) ($type['supports_options'] ?? false),
                'multiple' => (bool) ($type['multiple'] ?? false),
                'accept' => $accept,
            ];
        }

        return $types;
    }
}
