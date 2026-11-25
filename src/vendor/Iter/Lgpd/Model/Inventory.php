<?php

declare(strict_types=1);

namespace Iter\Lgpd\Model;

use Iter\Core\Config;

/**
 * The record of processing (LGPD art. 37): what each enabled module keeps or hands on, why, on which legal basis,
 * to whom and for how long. Each module declares its own in etc/lgpd.json, a list of activities:
 *
 *     {"module": "Customer", "activity": "Cadastro do cliente", "subjects": "Clientes",
 *      "data": ["nome", "e-mail"], "purpose": "…", "basis": ["contract"],
 *      "operators": [{"name": "Mercado Pago", "country": "Brasil", "purpose": "pagamento"}],
 *      "retention": "Enquanto a conta existir", "retention_config": "mail/log/retention_days",
 *      "handler": "Iter\\Customer\\Model\\PersonalData",
 *      "when": {"config": "system/backup/destination", "equals": "b2"}}
 *
 * "retention_config", "handler" and "when" are optional. "when" keeps the activity only while it happens:
 * a setting ("config") or a static method ("call", "Class::method") whose value equals "equals".
 */
final class Inventory
{
    public const FILE = 'etc' . DIRECTORY_SEPARATOR . 'lgpd.json';

    /** the legal bases of art. 7 the store may use */
    public const BASES = [
        'consent' => 'Consentimento (art. 7º, I)',
        'legal_obligation' => 'Obrigação legal (art. 7º, II)',
        'contract' => 'Execução de contrato (art. 7º, V)',
        'rights' => 'Exercício regular de direitos (art. 7º, VI)',
        'legitimate_interest' => 'Legítimo interesse (art. 7º, IX)',
        'credit' => 'Proteção do crédito (art. 7º, X)',
    ];

    public const EXTRA_OPERATORS = 'lgpd/inventory/extra_operators';

    /** the country of an operator the admin chooses in the settings (SMTP server, backup bucket): not known here */
    public const UNKNOWN_COUNTRY = 'conforme o provedor';

    /** @return list<array<string, mixed>> the activities that happen now, with the retention in force */
    public static function activities(): array
    {
        $out = [];
        foreach (\getModuleJson(self::FILE) as $activity) {
            if (!self::happens($activity['when'] ?? null)) {
                continue;
            }
            $activity['retention_now'] = self::retention($activity);
            $out[] = $activity;
        }

        return $out;
    }

    /**
     * Who receives personal data, once each: from the modules plus Configurações → LGPD → Outros operadores.
     *
     * @return list<array{name: string, country: string, purposes: list<string>, modules: list<string>}>
     */
    public static function operators(): array
    {
        $by = [];
        $add = static function (string $name, string $country, string $purpose, string $module) use (&$by): void {
            $key = mb_strtolower($name . '|' . $country);
            $by[$key] ??= ['name' => $name, 'country' => $country, 'purposes' => [], 'modules' => []];
            $by[$key]['purposes'] = array_values(array_unique([...$by[$key]['purposes'], $purpose]));
            $by[$key]['modules'] = array_values(array_unique([...$by[$key]['modules'], $module]));
        };
        foreach (self::activities() as $activity) {
            foreach ($activity['operators'] ?? [] as $op) {
                $add((string) $op['name'], (string) ($op['country'] ?? ''), (string) ($op['purpose'] ?? $activity['purpose']), (string) $activity['module']);
            }
        }
        foreach (self::extraOperators() as $op) {
            $add($op['name'], $op['country'], $op['purpose'], 'Configurações');
        }

        return array_values($by);
    }

    /** @return list<array{name: string, country: string, purpose: string}> "Nome | País | Para quê", one per line */
    public static function extraOperators(): array
    {
        $out = [];
        foreach (preg_split('/\R/', (string) Config::get(self::EXTRA_OPERATORS)) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line));
            if ($parts[0] !== '') {
                $out[] = ['name' => $parts[0], 'country' => $parts[1] ?? '', 'purpose' => $parts[2] ?? ''];
            }
        }

        return $out;
    }

    /**
     * The handlers of the enabled modules, for export and erase (LGPD-PLAN §4 and §5), by the name of the module's
     * first activity that names them ("Cadastro do cliente" => Customer's handler)
     *
     * @return array<string, PersonalDataInterface>
     */
    public static function handlers(): array
    {
        $out = [];
        foreach (\getModuleJson(self::FILE) as $activity) {
            $class = $activity['handler'] ?? null;
            if ($class && !in_array($class, array_map('get_class', $out), true)) {
                $out[(string) $activity['activity']] = new $class();
            }
        }

        return $out;
    }

    /**
     * What is wrong in the modules' lgpd.json (for the selftest): missing fields, unknown basis, a handler that
     * doesn't exist or doesn't implement PersonalDataInterface, a retention_config that isn't a setting.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $problems = [];
        $fields = Config::fields();
        foreach (\getModuleJson(self::FILE) as $i => $a) {
            $name = ($a['module'] ?? '?') . ' / ' . ($a['activity'] ?? "#{$i}");
            foreach (['module', 'activity', 'subjects', 'data', 'purpose', 'basis', 'retention'] as $key) {
                if (empty($a[$key])) {
                    $problems[] = "{$name}: falta {$key}";
                }
            }
            foreach ((array) ($a['basis'] ?? []) as $basis) {
                if (!isset(self::BASES[$basis])) {
                    $problems[] = "{$name}: base legal desconhecida {$basis}";
                }
            }
            foreach ($a['operators'] ?? [] as $op) {
                if (empty($op['name']) || empty($op['country'])) {
                    $problems[] = "{$name}: operador sem nome ou país";
                }
            }
            $handler = $a['handler'] ?? null;
            if ($handler && !is_subclass_of($handler, PersonalDataInterface::class)) {
                $problems[] = "{$name}: {$handler} não implementa PersonalDataInterface";
            }
            $path = $a['retention_config'] ?? null;
            if ($path && !isset($fields[$path])) {
                $problems[] = "{$name}: retention_config {$path} não é uma configuração";
            }
            $call = $a['when']['call'] ?? null;
            if ($call && !is_callable($call)) {
                $problems[] = "{$name}: when.call {$call} não existe";
            }
        }

        return $problems;
    }

    /** outside Brazil: an international transfer (art. 33); an unknown or empty country isn't */
    public static function isAbroad(string $country): bool
    {
        $c = mb_strtolower(trim($country));

        return $c !== '' && $c !== 'brasil' && $c !== self::UNKNOWN_COUNTRY;
    }

    /** the country as the customer reads it (policy text, storefront): the unknown one becomes "país a confirmar" */
    public static function countryLabel(string $country): string
    {
        return $country === self::UNKNOWN_COUNTRY ? 'país a confirmar' : $country;
    }

    /** the activity happens now (no "when", or its value matches) */
    private static function happens(?array $when): bool
    {
        if (!$when) {
            return true;
        }
        $value = isset($when['call']) ? (is_callable($when['call']) ? ($when['call'])() : null) : Config::get((string) ($when['config'] ?? ''));

        return (string) $value === (string) ($when['equals'] ?? '');
    }

    /** the declared retention, with the setting's current value when there is one */
    private static function retention(array $activity): string
    {
        $text = (string) $activity['retention'];
        $path = $activity['retention_config'] ?? null;
        if (!$path) {
            return $text;
        }
        $value = (int) Config::get($path);
        $unit = (string) (Config::fields()[$path]['unit'] ?? '');

        return $text . ' — hoje: ' . ($value > 0 ? trim("{$value} {$unit}") : 'sem limpeza automática') . ' (Configurações)';
    }
}
