<?php
$e = static fn ($v) => htmlspecialchars((string) $v);
$number = static fn ($v) => $v === null ? '' : str_replace('.', ',', (string) $v);
$cep = static fn (string $v) => $v === '' ? '' : substr($v, 0, 5) . '-' . substr($v, 5);
?>
<section class="mail-list shipping-rate-form">
    <h2>Faixas de frete</h2>
<?php if (count($websites) > 1): ?>
    <form method="get" action="/admin/shippingrate/index">
        <label>Website
            <select name="website_id" data-autosubmit>
    <?php foreach ($websites as $id => $name): ?>
                <option value="<?= (int) $id ?>" <?= (int) $id === $website_id ? 'selected' : '' ?>><?= $e($name) ?></option>
    <?php endforeach; ?>
            </select>
        </label>
    </form>
<?php endif; ?>
    <p><small>Para um CEP, vale a faixa com o menor "peso até" que comporte o peso do carrinho (vazio = qualquer peso). Liga e desliga o frete por tabela em Configurações → Frete.</small></p>
    <form method="post" action="/admin/shippingrate/save">
        <?= \Iter\Core\FormKey::field() ?>
        <input type="hidden" name="website_id" value="<?= (int) $website_id ?>">
        <table>
            <thead><tr><th>CEP inicial</th><th>CEP final</th><th>Peso até (kg)</th><th>Valor (R$)</th><th>Prazo (dias úteis)</th></tr></thead>
            <tbody id="shipping-rate-rows">
<?php foreach (array_merge($rates, [['postcode_from' => '', 'postcode_to' => '', 'weight_to' => null, 'price' => null, 'days' => null]]) as $i => $rate): ?>
                <tr>
                    <td><input type="text" name="rates[<?= $i ?>][postcode_from]" value="<?= $e($cep($rate['postcode_from'])) ?>" maxlength="9" inputmode="numeric"></td>
                    <td><input type="text" name="rates[<?= $i ?>][postcode_to]" value="<?= $e($cep($rate['postcode_to'])) ?>" maxlength="9" inputmode="numeric"></td>
                    <td><input type="text" name="rates[<?= $i ?>][weight_to]" value="<?= $e($number($rate['weight_to'])) ?>" inputmode="decimal"></td>
                    <td><input type="text" name="rates[<?= $i ?>][price]" value="<?= $e($rate['price'] === null ? '' : number_format($rate['price'], 2, ',', '')) ?>" inputmode="decimal"></td>
                    <td><input type="number" name="rates[<?= $i ?>][days]" value="<?= $e($rate['days']) ?>" min="0" max="365"></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
        <p>
            <button type="button" data-add-row="shipping-rate-rows">+ Adicionar faixa</button>
            <small>Linhas em branco são ignoradas: apague os campos de uma faixa para removê-la.</small>
        </p>
        <p><button type="submit">Salvar faixas</button></p>
    </form>
</section>
