<?php
use Iter\Core\FormKey;

$e = static fn ($v) => htmlspecialchars((string) $v);
?>
<section class="mail-list adminsales-start">
    <h2>Novo pedido</h2>
<?php if ($open): ?>
    <h3>Em montagem</h3>
    <table>
        <thead><tr><th>Atualizado</th><th>Website</th><th>Cliente</th><th></th></tr></thead>
        <tbody>
    <?php foreach ($open as $cart): ?>
            <tr>
                <td><?= $e(substr((string) $cart['updated_at'], 0, 16)) ?></td>
                <td><?= $e($websites[$cart['website_id']] ?? '') ?></td>
                <td><?= $e(trim($cart['firstname'] . ' ' . $cart['lastname']) ?: ($cart['customer_id'] ? 'Cliente #' . $cart['customer_id'] : 'Visitante')) ?></td>
                <td><a href="/admin/ordercreate/edit?id=<?= (int) $cart['quote_id'] ?>">Continuar</a></td>
            </tr>
    <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

    <h3>Para quem é o pedido</h3>
    <form method="get" action="/admin/ordercreate/index">
<?php if (count($websites) > 1): ?>
        <label>Website
            <select name="website_id">
    <?php foreach ($websites as $id => $name): ?>
                <option value="<?= (int) $id ?>" <?= (int) $id === $website_id ? 'selected' : '' ?>><?= $e($name) ?></option>
    <?php endforeach; ?>
            </select>
        </label>
<?php endif; ?>
        <input type="search" name="q" placeholder="Nome, e-mail ou CPF/CNPJ do cliente" value="<?= $e($q) ?>">
        <button type="submit">Buscar cliente</button>
    </form>
<?php if ($q !== ''): ?>
    <table>
        <thead><tr><th>Cliente</th><th>E-mail</th><th>CPF/CNPJ</th><th></th></tr></thead>
        <tbody>
    <?php if (!$customers): ?>
            <tr><td colspan="4">Nenhum cliente ativo encontrado neste website.</td></tr>
    <?php endif; ?>
    <?php foreach ($customers as $customer): ?>
            <tr>
                <td><?= $e(trim($customer['firstname'] . ' ' . $customer['lastname']) ?: $customer['company_name']) ?></td>
                <td><?= $e($customer['email']) ?></td>
                <td><?= $e($customer['taxvat']) ?></td>
                <td>
                    <form method="post" action="/admin/ordercreate/start">
                        <?= FormKey::field() ?>
                        <input type="hidden" name="website_id" value="<?= (int) $website_id ?>">
                        <input type="hidden" name="customer_id" value="<?= (int) $customer['customer_id'] ?>">
                        <button type="submit">Iniciar pedido</button>
                    </form>
                </td>
            </tr>
    <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php if ($guest_allowed): ?>
    <form method="post" action="/admin/ordercreate/start">
        <?= FormKey::field() ?>
        <input type="hidden" name="website_id" value="<?= (int) $website_id ?>">
        <p><button type="submit">Iniciar pedido para visitante (sem cadastro)</button></p>
    </form>
<?php else: ?>
    <p><small>Este website não vende para visitante (Configurações → Vendas): o pedido precisa de um cliente cadastrado.</small></p>
<?php endif; ?>
</section>
