<section class="admin-login-form">
    <h2>Alterar senha</h2>
    <form method="post" action="/admin/auth/passwordpost">
        <?= \Iter\Core\FormKey::field() ?>
        <p>
            <label>Senha atual<br>
                <input type="password" name="current_password" autocomplete="current-password" required autofocus>
            </label>
        </p>
        <p>
            <label>Nova senha<br>
                <input type="password" name="password" autocomplete="new-password" required>
            </label>
        </p>
        <p>
            <label>Confirmar nova senha<br>
                <input type="password" name="password_confirm" autocomplete="new-password" required>
            </label>
        </p>
        <p>
            <button type="submit" class="admin-button-primary">Salvar</button>
        </p>
    </form>
</section>
