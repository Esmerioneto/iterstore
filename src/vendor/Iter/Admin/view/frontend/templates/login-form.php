<section class="admin-login-form">
    <h2>Entrar</h2>
    <form method="post" action="/admin/auth/loginpost">
        <?= \Iter\Core\FormKey::field() ?>
        <p>
            <label>Usuário<br>
                <input type="text" name="username" autocomplete="username" required autofocus>
            </label>
        </p>
        <p>
            <label>Senha<br>
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
        </p>
        <p>
            <button type="submit">Entrar</button>
            <a href="/admin/auth/forgot">Esqueci minha senha</a>
        </p>
    </form>
</section>
