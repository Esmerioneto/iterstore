<section class="admin-user-form">
    <h2><?= $is_edit ? 'Editar usuário' : 'Novo usuário' ?></h2>
    <form method="post" action="/admin/user/save">
        <?= \Iter\Core\FormKey::field() ?>
<?php if ($user_id): ?>
        <input type="hidden" name="user_id" value="<?= (int) $user_id ?>">
<?php endif; ?>
        <p>
            <label>Nome<br>
                <input type="text" name="firstname" value="<?= htmlspecialchars((string) $values['firstname']) ?>">
            </label>
        </p>
        <p>
            <label>Sobrenome<br>
                <input type="text" name="lastname" value="<?= htmlspecialchars((string) $values['lastname']) ?>">
            </label>
        </p>
        <p>
            <label>E-mail<br>
                <input type="email" name="email" value="<?= htmlspecialchars((string) $values['email']) ?>" required>
            </label>
        </p>
        <p>
            <label>Usuário<br>
                <input type="text" name="username" value="<?= htmlspecialchars((string) $values['username']) ?>" required>
            </label>
        </p>
        <p>
            <label>Senha<?= $is_edit ? ' (deixe em branco para manter a atual)' : '' ?><br>
                <input type="password" name="password" <?= $is_edit ? '' : 'required' ?>>
            </label>
        </p>
        <p>
            <label>Confirmar senha<br>
                <input type="password" name="password_confirm">
            </label>
        </p>
        <p>
            <label>Idioma<br>
                <select name="interface_locale">
                    <option value="pt_BR" <?= $values['interface_locale'] === 'pt_BR' ? 'selected' : '' ?>>Português (Brasil)</option>
                    <option value="en_US" <?= $values['interface_locale'] === 'en_US' ? 'selected' : '' ?>>English (US)</option>
                </select>
            </label>
        </p>
        <p>
            <label>Perfil<br>
                <select name="role_id">
                    <option value="">Nenhum</option>
<?php foreach ($roles as $role): ?>
                    <option value="<?= (int) $role['role_id'] ?>" <?= (string) $values['role_id'] === (string) $role['role_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($role['role_name']) ?>
                    </option>
<?php endforeach; ?>
                </select>
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="is_active" value="1" <?= $values['is_active'] ? 'checked' : '' ?>>
                Ativo
            </label>
        </p>
        <p>
            <button type="submit" class="admin-button-primary">Salvar</button>
            <a href="/admin/user/index">Cancelar</a>
        </p>
    </form>
</section>
