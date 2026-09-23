<section class="admin-login-form">
    <h2>Nova senha</h2>
    <form method="post" action="/admin/auth/resetpost">
        <?= \Iter\Core\FormKey::field() ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <p>
            <label>Nova senha<br>
                <input type="password" name="password" autocomplete="new-password" required autofocus>
            </label>
        </p>
        <p>
            <label>Confirmar nova senha<br>
                <input type="password" name="password_confirm" autocomplete="new-password" required>
            </label>
        </p>
        <p>
            <button type="submit">Salvar</button>
        </p>
    </form>
</section>
