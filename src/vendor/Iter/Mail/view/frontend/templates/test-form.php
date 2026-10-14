<section class="mail-test-form">
    <h2>Testar envio de e-mail</h2>
    <form method="post" action="/admin/mailtest/send">
        <?= \Iter\Core\FormKey::field() ?>
        <input type="hidden" name="website_id" value="<?= (int) $website_id ?>">
        <p>Envia agora um e-mail de teste para <strong><?= htmlspecialchars($email) ?></strong>, com as configurações salvas<?= $website_id ? ' deste website' : '' ?>. Salve as mudanças antes de testar.</p>
        <p><button type="submit" class="admin-button-primary">Enviar e-mail de teste</button></p>
    </form>
</section>
