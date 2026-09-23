<?php

declare(strict_types=1);

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use Iter\Admin\Model\AdminRole;
use Iter\Admin\Model\AdminUser;

// Cria (ou reaproveita) o perfil "Administradores" com acesso total
// (Iter_Admin::root) e um usuário nele — sem isso ninguém consegue logar.
[, $username, $password, $email] = $argv + [null, null, null, null];

if (!$username || !$password || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php bin/create-admin.php <usuario> <senha> <email>\n");
    exit(1);
}

if ($error = AdminUser::validatePasswordStrength($password)) {
    fwrite(STDERR, $error . "\n");
    exit(1);
}

$role = new AdminRole();
$role->getResource()->load($role, 'Administradores', 'role_name');
if (!$role->getId()) {
    $role->setData('role_name', 'Administradores');
}
$role->setResources(['Iter_Admin::root'])->save();

$user = new AdminUser();
$user->getResource()->load($user, $username, 'username');
$user->addData([
    'username' => $username,
    'email' => $email,
    'is_active' => 1,
    'role_id' => (int) $role->getId(),
])->setPassword($password)->save();

echo "Admin '{$username}' pronto (perfil Administradores, user_id {$user->getId()}).\n";
