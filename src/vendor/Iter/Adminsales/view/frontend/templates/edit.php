<?php
use Iter\Core\FormKey;
use Iter\Inventory\Model\StockService;

$e = static fn ($v) => htmlspecialchars((string) $v);
$money = static fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
$qty = static fn ($v) => str_replace('.', ',', StockService::format((string) $v));
$id = (int) $quote['quote_id'];
// every form posts the cart id and the session's form key
$form = static fn (string $action, string $inner, string $attrs = '') => '<form method="post" action="/admin/ordercreate/' . $action . '"' . $attrs . '>'
    . FormKey::field() . '<input type="hidden" name="quote_id" value="' . $id . '">' . $inner . '</form>';
$isGuest = $cart['customer_id'] === null;
$addressForm = static function (string $type, ?array $current) use ($e, $form, $address_labels, $address_fields, $regions): string {
    $value = static fn (string $key) => $e($current[$key] ?? '');
    ob_start(); ?>
    <fieldset data-cep-scope>
        <p><label><?= $e($address_labels['postcode'] ?? 'CEP') ?><br><input type="text" name="address[postcode]" value="<?= $value('postcode') ?>" data-mask="cep" data-cep="lookup"></label></p>
        <p><label><?= $e($address_labels['street'] ?? 'Rua') ?><br><input type="text" name="address[street]" maxlength="255" value="<?= $value('street') ?>" data-cep="logradouro"></label></p>
        <p><label><?= $e($address_labels['number'] ?? 'Número') ?><br><input type="text" name="address[number]" maxlength="20" size="8" value="<?= $value('number') ?>"></label></p>
        <p><label><?= $e($address_labels['district'] ?? 'Bairro') ?><br><input type="text" name="address[district]" maxlength="255" value="<?= $value('district') ?>" data-cep="bairro"></label></p>
        <p><label><?= $e($address_labels['city'] ?? 'Cidade') ?><br><input type="text" name="address[city]" maxlength="255" value="<?= $value('city') ?>" data-cep="localidade"></label></p>
        <p><label><?= $e($address_labels['region'] ?? 'Estado') ?><br>
            <select name="address[region]" data-cep="uf">
                <option value="">Selecione</option>
<?php foreach ($regions as $uf => $name): ?>
                <option value="<?= $uf ?>" <?= ($current['region'] ?? '') === $uf ? 'selected' : '' ?>><?= $uf ?> — <?= $e($name) ?></option>
<?php endforeach; ?>
            </select>
        </label></p>
<?php (static function (array $schema, array $values, string $name_prefix): void {
    $form_id = null;
    require (new \Iter\Core\View\ComponentResolver())->resolveTemplate('eav/form-fields');
})(['fields' => $address_fields], $current ?? [], 'address'); ?>
        <p><button type="submit"><?= $type === 'shipping' ? 'Salvar endereço de entrega' : 'Salvar endereço de cobrança' ?></button></p>
    </fieldset>
<?php
    return $form('address', '<input type="hidden" name="type" value="' . $type . '">' . ob_get_clean());
};
?>
<section class="mail-view adminsales-edit">
    <h2>Novo pedido · <?= $e($website_name) ?></h2>

    <h3>Cliente</h3>
<?php if (!$isGuest): ?>
    <p><?= $e($cart['firstname'] . ' ' . $cart['lastname']) ?> · <?= $e($cart['email']) ?> · CPF/CNPJ <?= $e($cart['taxvat']) ?>
        · <a href="/admin/customer/edit?id=<?= (int) $cart['customer_id'] ?>">ver cliente</a></p>
<?php else: ?>
    <?php if (!$guest_allowed): ?>
    <p><strong>Este website não vende para visitante (Configurações → Vendas): o pedido será recusado.</strong> Descarte e comece com um cliente cadastrado.</p>
    <?php endif; ?>
    <?= $form('guest', '<label>E-mail <input type="email" name="email" value="' . $e($cart['email']) . '" required></label>'
        . ' <label>Nome <input type="text" name="firstname" value="' . $e($cart['firstname']) . '" required></label>'
        . ' <label>Sobrenome <input type="text" name="lastname" value="' . $e($cart['lastname']) . '" required></label>'
        . ' <label>CPF/CNPJ <input type="text" name="taxvat" value="' . $e($cart['taxvat']) . '" data-mask="cpf_cnpj" required></label>'
        . ' <button type="submit">Salvar dados do visitante</button>') ?>
<?php endif; ?>

    <h3>Itens</h3>
    <table>
        <thead><tr><th>SKU</th><th>Produto</th><th>Preço</th><th>Qtd.</th><th>Total</th><th></th></tr></thead>
        <tbody>
<?php if (!$cart['items']): ?>
            <tr><td colspan="6">Nenhum item. Busque um produto abaixo.</td></tr>
<?php endif; ?>
<?php foreach ($cart['items'] as $item): ?>
            <tr>
                <td><?= $e($item['sku']) ?></td>
                <td><?= $e($item['name']) ?><?= $item['error'] ? '<br><strong>' . $e($item['error']) . '</strong>' : '' ?></td>
                <td><?= $item['price'] === null ? '—' : $money($item['price']) ?></td>
                <td><?= $form('updateitem', '<input type="hidden" name="item_id" value="' . (int) $item['id'] . '"><input type="text" name="quantity" size="5" inputmode="decimal" value="' . $e($qty($item['quantity'])) . '"> <button type="submit">Alterar</button>') ?></td>
                <td><?= $money($item['row_total']) ?></td>
                <td><?= $form('updateitem', '<input type="hidden" name="item_id" value="' . (int) $item['id'] . '"><input type="hidden" name="quantity" value="0"><button type="submit">Remover</button>') ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <form method="get" action="/admin/ordercreate/edit">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="search" name="q" placeholder="SKU ou nome do produto" value="<?= $e($q) ?>">
        <button type="submit">Buscar produto</button>
    </form>
<?php if ($q !== ''): ?>
    <table>
        <thead><tr><th>SKU</th><th>Produto</th><th>Preço</th><th>Disponível</th><th>Adicionar</th></tr></thead>
        <tbody>
    <?php if (!$products): ?>
            <tr><td colspan="5">Nenhum produto ativo encontrado neste website.</td></tr>
    <?php endif; ?>
    <?php foreach ($products as $product): ?>
        <?php if (!$product['variants']): ?>
            <tr><td><?= $e($product['sku']) ?></td><td><?= $e($product['name']) ?></td><td colspan="3">Sem variação ativa.</td></tr>
        <?php endif; ?>
        <?php foreach ($product['variants'] as $variant): ?>
            <tr>
                <td><?= $e($variant['sku']) ?></td>
                <td><?= $e($product['name']) ?><?= $variant['options'] ? '<br><small>' . $e($variant['options']) . '</small>' : '' ?></td>
                <td><?= $variant['price'] === null ? 'sem preço' : $money($variant['price']) ?></td>
                <td><?= $e($qty($variant['salable'])) ?></td>
                <td><?= $form('additem', '<input type="hidden" name="sku" value="' . $e($variant['sku']) . '"><input type="text" name="quantity" size="5" inputmode="decimal" value="1"> <button type="submit">Adicionar</button>') ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

    <h3>Endereço de entrega</h3>
<?php if ($cart['shipping_address']): ?>
    <p>Atual: <?= $e(implode(', ', array_filter([$cart['shipping_address']['street'] . ', ' . $cart['shipping_address']['number'], $cart['shipping_address']['district'],
        $cart['shipping_address']['city'] . '/' . $cart['shipping_address']['region'], 'CEP ' . $cart['shipping_address']['postcode']]))) ?></p>
<?php endif; ?>
<?php foreach ($saved_addresses as $saved): ?>
    <?= $form('address', '<input type="hidden" name="type" value="shipping"><input type="hidden" name="customer_address_id" value="' . (int) $saved['address_id'] . '">'
        . $e($saved['street'] . ', ' . $saved['number'] . ' — ' . $saved['city'] . '/' . $saved['region']) . ' <button type="submit">Usar este</button>') ?>
<?php endforeach; ?>
    <details<?= $cart['shipping_address'] || $saved_addresses ? '' : ' open' ?>>
        <summary>Outro endereço</summary>
        <?= $addressForm('shipping', $cart['shipping_address']) ?>
    </details>

    <h3>Endereço de cobrança</h3>
<?php if ($cart['billing_same_as_shipping']): ?>
    <p>Igual ao de entrega.</p>
<?php else: ?>
    <?= $form('address', '<input type="hidden" name="type" value="billing"><input type="hidden" name="same" value="1"><button type="submit">Usar o de entrega</button>') ?>
<?php endif; ?>
    <details>
        <summary>Outro endereço de cobrança</summary>
        <?= $addressForm('billing', $cart['billing_same_as_shipping'] ? null : $cart['billing_address']) ?>
    </details>

<?php if (isset($cart['shipping_methods'])): ?>
    <h3>Frete</h3>
    <?php if (!$cart['shipping_methods']): ?>
    <p>Nenhum frete disponível<?= $cart['shipping_address'] ? ' para este endereço' : ': informe o endereço de entrega' ?>.</p>
    <?php endif; ?>
    <?php foreach ($cart['shipping_methods'] as $method): ?>
    <?= $form('shipping', '<input type="hidden" name="code" value="' . $e($method['code']) . '">' . $e($method['label']) . ' · ' . $money($method['amount']) . ' · ' . (int) $method['days'] . ' dia(s) útil(eis) '
        . (($cart['shipping_method']['code'] ?? null) === $method['code'] ? '<strong>(escolhido)</strong>' : '<button type="submit">Escolher</button>')) ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (isset($cart['payment_methods'])): ?>
    <h3>Pagamento</h3>
    <?php if (!$cart['payment_methods']): ?>
    <p>Nenhuma forma de pagamento ativa (Configurações → Pagamento).</p>
    <?php endif; ?>
    <?php foreach ($cart['payment_methods'] as $method): ?>
    <?= $form('payment', '<input type="hidden" name="code" value="' . $e($method['code']) . '">' . $e($method['label']) . ' '
        . (($cart['payment_method']['code'] ?? null) === $method['code'] ? '<strong>(escolhido)</strong>' : '<button type="submit">Escolher</button>')) ?>
    <?php endforeach; ?>
<?php endif; ?>

    <h3>Fechar</h3>
    <p>Subtotal <?= $money($cart['subtotal']) ?> · Frete <?= $money($cart['shipping_amount']) ?> · <strong>Total <?= $money($cart['total']) ?></strong></p>
    <?= $form('place', ($can_confirm_payment ? '<p><label><input type="checkbox" name="payment_received" value="1"> Pagamento recebido (já confirma o pagamento)</label></p>' : '')
        . '<p><label><input type="checkbox" name="notify" value="1" checked> Avisar o cliente por e-mail</label></p>'
        . '<p><label>Comentário interno (não vai para o cliente)<br><textarea name="comment" rows="2" maxlength="5000"></textarea></label></p>'
        . '<p><button type="submit">Criar pedido</button></p>') ?>
    <?= $form('discard', '<button type="submit">Descartar</button>', ' data-confirm="Descartar este pedido em montagem?"') ?>
    <p><a href="/admin/ordercreate/index">Voltar</a></p>
</section>
