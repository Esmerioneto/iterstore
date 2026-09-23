<section class="admin-login-form">
    <h2>Esqueci minha senha</h2>
    <p>Informe o e-mail cadastrado. Enviaremos um link para você escolher uma nova senha.</p>
    <form method="post" action="/admin/auth/forgotpost">
        <?= \Iter\Core\FormKey::field() ?>
        <p>
            <label>E-mail<br>
                <input type="email" name="email" autocomplete="email" required autofocus>
            </label>
        </p>
        <p>
            <button type="submit">Enviar link</button>
            <a href="/admin/auth/login">Voltar ao login</a>
        </p>
    </form>
</section>
