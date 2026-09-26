<?php

declare(strict_types=1);

namespace Iter\Admin\Controllers;

use Iter\Admin\Auth;
use Iter\Admin\Breadcrumb;
use Iter\Admin\Model\AdminUser;
use Iter\Core\Messages;
use Iter\Core\View\Layout;

class User
{
    public const ADMIN_RESOURCE = 'Iter_Admin::user';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Usuários admin'],
        ]);

        echo Layout::renderAdmin('admin_user_index');
    }

    public function Create(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Usuários admin', 'url' => '/admin/user/index'],
            ['label' => 'Novo'],
        ]);

        echo Layout::renderAdmin('admin_user_form');
    }

    public function Edit(): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $user = new AdminUser();
        if ($id > 0) {
            $user->load($id);
        }

        if (!$user->getId()) {
            Messages::addError('Usuário não encontrado.');
            header('Location: /admin/user/index');
            exit;
        }

        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Usuários admin', 'url' => '/admin/user/index'],
            ['label' => 'Editar'],
        ]);

        echo Layout::renderAdmin('admin_user_form');
    }

    public function Save(): void
    {
        $data = $_POST;
        $id = !empty($data['user_id']) ? (int) $data['user_id'] : null;
        $redirect = $id ? '/admin/user/edit?id=' . $id : '/admin/user/create';

        $errors = $this->validate($data, $id);
        if (!empty($errors)) {
            foreach ($errors as $error) {
                Messages::addError($error);
            }
            $_SESSION['iter_old_input'] = $data;
            header('Location: ' . $redirect);
            exit;
        }

        $model = new AdminUser();
        if ($id) {
            $model->load($id);
        }

        $model->addData([
            'firstname' => $data['firstname'] ?? null,
            'lastname' => $data['lastname'] ?? null,
            'email' => $data['email'] ?? null,
            'username' => $data['username'],
            'interface_locale' => $data['interface_locale'] ?? 'pt_BR',
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'role_id' => !empty($data['role_id']) ? (int) $data['role_id'] : null,
        ]);

        if (!empty($data['password'])) {
            $model->setPassword($data['password']);
        }

        try {
            $model->save();
            Messages::addSuccess($id ? 'Usuário atualizado com sucesso.' : 'Usuário criado com sucesso.');
        } catch (\PDOException $e) {
            Messages::addError('Não foi possível salvar: nome de usuário já existe.');
            $_SESSION['iter_old_input'] = $data;
            header('Location: ' . $redirect);
            exit;
        }

        header('Location: /admin/user/index');
        exit;
    }

    public function Delete(): void
    {
        $id = !empty($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        if ($id === (int) Auth::user()->getId()) {
            Messages::addError('Você não pode remover o próprio usuário.');
        } elseif ($id > 0) {
            (new AdminUser())->setId($id)->delete();
            Messages::addSuccess('Usuário removido com sucesso.');
        }

        header('Location: /admin/user/index');
        exit;
    }

    private function validate(array $data, ?int $id): array
    {
        $errors = [];

        if (trim($data['username'] ?? '') === '') {
            $errors[] = 'Usuário é obrigatório.';
        }

        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Informe um e-mail válido.';
        }

        if ($id === null && empty($data['password'])) {
            $errors[] = 'Senha é obrigatória.';
        }

        // the logged-in admin can't lock themselves out: another admin has to do it
        $self = Auth::user();
        if ($id !== null && $id === (int) $self->getId()) {
            if (empty($data['is_active'])) {
                $errors[] = 'Você não pode desativar o próprio usuário.';
            }
            if ((int) ($data['role_id'] ?? 0) !== (int) $self->getData('role_id')) {
                $errors[] = 'Você não pode alterar o próprio perfil.';
            }
        }

        if (!empty($data['password']) && $data['password'] !== ($data['password_confirm'] ?? '')) {
            $errors[] = 'As senhas não coincidem.';
        }

        if (!empty($data['password']) && ($strengthError = AdminUser::validatePasswordStrength((string) $data['password']))) {
            $errors[] = $strengthError;
        }

        if ($id !== null && !empty($data['password']) && (new AdminUser())->load($id)->isPasswordReused((string) $data['password'])) {
            $errors[] = 'A nova senha não pode repetir uma das senhas usadas recentemente.';
        }

        return $errors;
    }
}
