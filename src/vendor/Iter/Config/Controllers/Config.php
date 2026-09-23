<?php

declare(strict_types=1);

namespace Iter\Config\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Core\Config as SystemConfig;
use Iter\Core\Messages;
use Iter\Core\View\Layout;

class Config
{
    public const ADMIN_RESOURCE = 'Iter_Config::config';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Configurações'],
        ]);

        echo Layout::renderAdmin('admin_config_index');
    }

    public function Save(): void
    {
        $input = is_array($_POST['config'] ?? null) ? $_POST['config'] : [];
        $values = [];
        $errors = [];

        // only declared paths are saved; anything else posted is ignored
        foreach (SystemConfig::fields() as $path => $field) {
            if (!isset($input[$path]) || !is_string($input[$path])) {
                continue;
            }

            $value = trim($input[$path]);
            if (($field['type'] ?? 'text') === 'boolean') {
                $value = $value === '1' ? '1' : '0';
            } elseif (($field['type'] ?? 'text') === 'number') {
                $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => array_filter([
                    'min_range' => $field['min'] ?? null,
                    'max_range' => $field['max'] ?? null,
                ], 'is_int')]);
                if ($number === false) {
                    $errors[] = sprintf('"%s" deve ser um número inteiro entre %s e %s.', $field['label'], $field['min'] ?? '-∞', $field['max'] ?? '∞');
                    continue;
                }
                $value = (string) $number;
            }

            $values[$path] = $value;
        }

        if ($errors) {
            foreach ($errors as $error) {
                Messages::addError($error);
            }
            $_SESSION['iter_old_input'] = array_filter($input, 'is_string');
        } else {
            SystemConfig::save($values);
            Messages::addSuccess('Configurações salvas.');
        }

        header('Location: /admin/config/index');
        exit;
    }
}
