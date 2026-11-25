<?php
use Iter\Lgpd\Model\RequestService;

$e = static fn ($v) => htmlspecialchars((string) $v);
?>
<section class="lgpd-request-form">
    <h2>Nova solicitação</h2>
    <p class="admin-list-summary">Um pedido que chegou por e-mail, telefone ou pessoalmente. Antes de atender, confirme que quem pediu é o titular (ex.: resposta do próprio e-mail cadastrado). O prazo de <?= RequestService::DUE_DAYS ?> dias conta a partir de agora.</p>
    <form method="post" action="/admin/lgpdrequest/save" class="lgpd-form">
        <?= \Iter\Core\FormKey::field() ?>
<?php if (count($websites) > 1): ?>
        <label>Website
            <select name="website_id">
    <?php foreach ($websites as $value => $label): ?>
                <option value="<?= (int) $value ?>" <?= (string) $values['website_id'] === (string) $value ? 'selected' : '' ?>><?= $e($label) ?></option>
    <?php endforeach; ?>
            </select>
        </label>
<?php else: ?>
        <input type="hidden" name="website_id" value="<?= (int) array_key_first($websites) ?>">
<?php endif; ?>
        <label>E-mail do titular
            <input type="email" name="email" required value="<?= $e($values['email']) ?>">
            <small>Com cadastro neste e-mail, a solicitação fica ligada ao cliente; sem cadastro, é de um visitante (os pedidos feitos com o e-mail).</small>
        </label>
        <label>O que pediu
            <select name="type">
<?php foreach (RequestService::TYPES as $value => $label): ?>
                <option value="<?= $e($value) ?>" <?= $values['type'] === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
<?php endforeach; ?>
            </select>
        </label>
        <label class="lgpd-wide">Observação
            <textarea name="note" rows="3" placeholder="Como chegou, o que pediu exatamente, como a identidade foi confirmada"><?= $e($values['note']) ?></textarea>
        </label>
        <div class="lgpd-wide">
            <button type="submit" class="admin-button-primary">Registrar</button>
            <a href="/admin/lgpdrequest/index">Cancelar</a>
        </div>
    </form>
</section>
