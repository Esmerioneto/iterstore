<?php

declare(strict_types=1);

namespace Iter\Config\Controllers;

use Iter\Admin\Auth;
use Iter\Admin\Breadcrumb;
use Iter\Config\ModuleManager;
use Iter\Core\Config as SystemConfig;
use Iter\Core\Crypt;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Log\Model\OperationLog;
use Iter\Store\Model\Website;

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
        $useDefault = is_array($_POST['config_default'] ?? null) ? $_POST['config_default'] : [];
        $clear = is_array($_POST['config_clear'] ?? null) ? $_POST['config_clear'] : [];
        $websiteId = (int) ($_POST['website_id'] ?? 0);
        $back = '/admin/config/index' . ($websiteId ? '?website=' . $websiteId : '');
        if ($websiteId && !SystemConfig::websiteExists($websiteId)) {
            Messages::addError('Website não encontrado.');
            header('Location: /admin/config/index');
            exit;
        }
        $values = [];
        $defaults = [];
        $errors = [];
        $uploads = []; // path => [tmp file, extension], moved only when nothing failed
        $oldFiles = []; // this scope's replaced/removed images, deleted after saving
        $labels = []; // path => label, for the operation log

        // only declared paths are saved (in a website, only "scope": "website" ones); anything else posted is ignored
        foreach (SystemConfig::fields() as $path => $field) {
            $type = $field['type'] ?? 'text';
            // the messages name the block too: "Cancelar o pedido não pago em" is in Pix manual and in Combinar com a loja
            $field['label'] = isset($field['block']) && $field['block'] !== $field['label'] ? $field['block'] . ': ' . $field['label'] : $field['label'];
            if ($websiteId && ($field['scope'] ?? '') !== 'website') {
                continue;
            }
            // "admin_only": only the Administrador profile changes it (the operation log's switch); for others it's ignored
            if (!empty($field['admin_only']) && !Auth::isAllowed('Iter_Admin::root')) {
                continue;
            }
            $labels[$path] = $field['label'];
            if ($websiteId && !empty($useDefault[$path])) {
                $defaults[] = $path;
                continue;
            }
            if ($type === 'image') {
                $own = $websiteId ? SystemConfig::websiteValue($path, $websiteId) : SystemConfig::get($path);
                $file = self::uploadedFile($path);
                if ($file !== null) {
                    $mime = $file['error'] === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name']) ? mime_content_type($file['tmp_name']) : false;
                    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > SystemConfig::IMAGE_MAX_BYTES) {
                        $errors[] = sprintf('"%s": o arquivo passa de 2 MB.', $field['label']);
                    } elseif (!isset(SystemConfig::IMAGE_TYPES[$mime])) {
                        $errors[] = sprintf('"%s": envie uma imagem PNG, JPG ou WEBP.', $field['label']);
                    } else {
                        $uploads[$path] = [$file['tmp_name'], SystemConfig::IMAGE_TYPES[$mime]];
                        $oldFiles[] = $own;
                    }
                } elseif (!empty($clear[$path]) && (string) $own !== '') {
                    $values[$path] = '';
                    $oldFiles[] = $own;
                }
                continue;
            }
            if ($type === 'password' && !empty($clear[$path])) {
                $values[$path] = '';
                continue;
            }
            if (!isset($input[$path]) || !is_string($input[$path])) {
                continue;
            }

            $value = trim($input[$path]);
            if ($type === 'password') {
                // empty keeps the saved one; never shown back, stored encrypted
                if ($input[$path] !== '') {
                    $values[$path] = Crypt::encrypt($input[$path]);
                }
                continue;
            }
            if ($value === '' && ($field['required'] ?? true) === false) {
                $values[$path] = '';
                continue;
            }
            if ($type === 'boolean') {
                $value = $value === '1' ? '1' : '0';
            } elseif ($type === 'select') {
                if (!isset($field['options'][$value])) {
                    $errors[] = sprintf('"%s": opção inválida.', $field['label']);
                    continue;
                }
            } elseif ($type === 'email') {
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = sprintf('"%s" deve ser um e-mail válido.', $field['label']);
                    continue;
                }
            } elseif ($type === 'url') {
                if (!filter_var($value, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $value)) {
                    $errors[] = sprintf('"%s" deve ser um endereço http:// ou https://.', $field['label']);
                    continue;
                }
                $value = rtrim($value, '/');
            } elseif ($type === 'number') {
                $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => array_filter([
                    'min_range' => $field['min'] ?? null,
                    'max_range' => $field['max'] ?? null,
                ], 'is_int')]);
                if ($number === false) {
                    $errors[] = sprintf('"%s" deve ser um número inteiro entre %s e %s.', $field['label'], $field['min'] ?? '-∞', $field['max'] ?? '∞');
                    continue;
                }
                $value = (string) $number;
            } elseif ($value === '') {
                $errors[] = sprintf('"%s" é obrigatório.', $field['label']);
                continue;
            }

            $values[$path] = $value;
        }

        if ($errors) {
            foreach ($errors as $error) {
                Messages::addError($error);
            }
            // typed passwords aren't kept in the session
            $_SESSION['iter_old_input'] = array_filter($input, static fn ($v, $path) => is_string($v) && (SystemConfig::fields()[$path]['type'] ?? '') !== 'password', ARRAY_FILTER_USE_BOTH);
        } else {
            foreach ($uploads as $path => [$tmp, $ext]) {
                // random name: the upload never picks its own path or extension
                $values[$path] = 'config/' . bin2hex(random_bytes(16)) . '.' . $ext;
                is_dir(SystemConfig::mediaDir() . 'config') || mkdir(SystemConfig::mediaDir() . 'config', 0775, true);
                move_uploaded_file($tmp, SystemConfig::mediaDir() . $values[$path]);
            }
            foreach ($defaults as $path) {
                if ((SystemConfig::fields()[$path]['type'] ?? '') === 'image') {
                    $oldFiles[] = SystemConfig::websiteValue($path, $websiteId);
                }
            }
            $touched = array_intersect_key($labels, array_flip([...array_keys($values), ...$defaults]));
            $before = self::readable($touched, $websiteId, $values);
            SystemConfig::save($values, $websiteId ?: null);
            if ($defaults) {
                SystemConfig::clear($defaults, $websiteId);
            }
            self::record($touched, $websiteId, $before, self::readable($touched, $websiteId, $values, true));
            foreach ($oldFiles as $old) {
                if (preg_match('#^config/[0-9a-f]{32}\.(png|jpg|webp)$#', (string) $old)) {
                    @unlink(SystemConfig::mediaDir() . $old);
                }
            }
            Messages::addSuccess('Configurações salvas.');
        }

        header('Location: ' . $back);
        exit;
    }

    public function Module(): void
    {
        $key = (string) ($_POST['module'] ?? '');
        $enabled = ($_POST['enabled'] ?? '') === '1';
        if (!Auth::isAllowed('Iter_Config::modules')) {
            Messages::addError('Seu perfil não tem permissão para ativar ou desativar módulos.');
        } else {
            try {
                ModuleManager::setEnabled($key, $enabled);
                $name = ucfirst(\getModuleName($key));
                Messages::addSuccess($enabled
                    ? "Módulo {$name} ativado. Se é a primeira ativação, rode php bin/migrate.php up para criar as tabelas dele."
                    : "Módulo {$name} desativado. Os dados dele continuam no banco.");
            } catch (\DomainException $e) {
                Messages::addError($e->getMessage());
            }
        }

        header('Location: /admin/config/index');
        exit;
    }

    /**
     * The settings as the operation log shows them: label => readable value
     *
     * @param array<string, string> $touched path => label
     * @param array<string, string> $values what's being saved (a password is never shown, only whether it changed)
     */
    private static function readable(array $touched, int $websiteId, array $values, bool $after = false): array
    {
        $readable = [];
        foreach ($touched as $path => $label) {
            $field = SystemConfig::fields()[$path];
            $value = $websiteId ? SystemConfig::websiteValue($path, $websiteId) : SystemConfig::get($path);
            $readable[$label] = match (true) {
                $value === null => $websiteId ? 'usa o valor padrão' : '',
                ($field['type'] ?? '') === 'password' => (string) $value === '' ? '' : ($after && array_key_exists($path, $values) ? 'alterada' : 'definida'),
                ($field['type'] ?? '') === 'boolean' => (string) $value === '1' ? 'ligado' : 'desligado',
                ($field['type'] ?? '') === 'select' => (string) ($field['options'][$value] ?? $value),
                default => (string) $value,
            };
        }

        return $readable;
    }

    /** one line per save; turning the operation log off or on is its own action, written even while it's off */
    private static function record(array $touched, int $websiteId, array $before, array $after): void
    {
        $action = 'config';
        $switch = $touched[OperationLog::ENABLED] ?? null;
        if ($switch !== null && ($before[$switch] ?? null) !== ($after[$switch] ?? null)) {
            $action = $after[$switch] === 'ligado' ? 'log_on' : 'log_off';
        }
        $scope = $websiteId ? 'Website: ' . (array_column((new Website())->getList(), 'name', 'website_id')[$websiteId] ?? $websiteId) : 'Padrão';
        OperationLog::record($action, 'config', $websiteId ?: null, 'Configurações — ' . $scope, $before, $after);
    }

    /** config[<path>] file from the multipart form; null when none was chosen */
    private static function uploadedFile(string $path): ?array
    {
        $files = $_FILES['config'] ?? null;
        if (!is_array($files) || !isset($files['error'][$path]) || !is_int($files['error'][$path]) || $files['error'][$path] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return ['tmp_name' => (string) $files['tmp_name'][$path], 'size' => (int) $files['size'][$path], 'error' => $files['error'][$path]];
    }
}
