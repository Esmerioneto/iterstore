<?php
use Iter\Customer\Model\CustomerService;
use Iter\Eav\Model\FormLayout;

// Same block structure as the product form (fields point to the empty <form> with
// form="customer-form"). The "Pessoa física"/"Pessoa jurídica" blocks follow the type
// select (admin.js): the hidden one's <fieldset> is disabled, so its fields aren't posted.
$e = static fn ($v) => htmlspecialchars((string) $v);
$type = $values['customer_type'] === 'pj' ? 'pj' : 'pf';
$taxvat = CustomerService::formatTaxvat($values['taxvat']);
$blocks = [];

ob_start(); ?>
<fieldset>
    <legend>Conta</legend>
    <p>
        <label><?= $e($labels['customer_type']) ?> *<br>
            <select form="customer-form" name="customer_type" data-customer-type required>
<?php foreach ($types as $code => $label): ?>
                <option value="<?= $e($code) ?>" <?= $code === $type ? 'selected' : '' ?>><?= $e($label) ?></option>
<?php endforeach; ?>
            </select>
        </label>
    </p>
    <p>
        <label>Website *<br>
            <select form="customer-form" name="website_id" required>
                <option value="">Selecione</option>
<?php foreach ($websites as $websiteId => $name): ?>
                <option value="<?= (int) $websiteId ?>" <?= (string) $values['website_id'] === (string) $websiteId ? 'selected' : '' ?>><?= $e($name) ?></option>
<?php endforeach; ?>
            </select>
        </label>
    </p>
    <p>
        <label>Grupo *<br>
            <select form="customer-form" name="group_id" required>
<?php foreach ($groups as $groupId => $name): ?>
                <option value="<?= (int) $groupId ?>" <?= (string) $values['group_id'] === (string) $groupId ? 'selected' : '' ?>><?= $e($name) ?></option>
<?php endforeach; ?>
            </select>
        </label>
    </p>
    <p>
        <label><?= $e($labels['email']) ?> *<br>
            <input form="customer-form" type="email" name="email" maxlength="255" value="<?= $e($values['email']) ?>" data-mask="email" required>
        </label>
    </p>
    <p>
        <label><?= $e($labels['firstname']) ?> *<br>
            <input form="customer-form" type="text" name="firstname" maxlength="255" value="<?= $e($values['firstname']) ?>" required>
        </label>
    </p>
    <p>
        <label><?= $e($labels['lastname']) ?> *<br>
            <input form="customer-form" type="text" name="lastname" maxlength="255" value="<?= $e($values['lastname']) ?>" required>
        </label>
    </p>
    <p>
        <label><input form="customer-form" type="checkbox" name="is_active" value="1" <?= $values['is_active'] ? 'checked' : '' ?>> Ativo</label>
    </p>
</fieldset>
<?php $blocks['account'] = ['title' => 'Conta', 'html' => ob_get_clean()];

ob_start(); ?>
<fieldset<?= $type === 'pf' ? '' : ' disabled' ?>>
    <legend>Pessoa física</legend>
    <p>
        <label>CPF *<br>
            <input form="customer-form" type="text" name="cpf" value="<?= $type === 'pf' ? $e($taxvat) : '' ?>" data-mask="cpf" required>
        </label>
    </p>
    <p>
        <label><?= $e($labels['dob']) ?><br>
            <input form="customer-form" type="date" name="dob" value="<?= $e(substr((string) $values['dob'], 0, 10)) ?>" max="<?= date('Y-m-d') ?>">
        </label>
    </p>
</fieldset>
<?php $blocks['pf'] = ['title' => 'Pessoa física', 'html' => ob_get_clean(), 'attrs' => ' data-customer-type-block="pf"' . ($type === 'pf' ? '' : ' hidden')];

ob_start(); ?>
<fieldset<?= $type === 'pj' ? '' : ' disabled' ?>>
    <legend>Pessoa jurídica</legend>
    <p>
        <label>CNPJ *<br>
            <input form="customer-form" type="text" name="cnpj" value="<?= $type === 'pj' ? $e($taxvat) : '' ?>" data-mask="cnpj" required>
        </label>
    </p>
    <p>
        <label><?= $e($labels['company_name']) ?> *<br>
            <input form="customer-form" type="text" name="company_name" maxlength="255" value="<?= $e($values['company_name']) ?>" required>
        </label>
    </p>
    <p>
        <label><?= $e($labels['trade_name']) ?><br>
            <input form="customer-form" type="text" name="trade_name" maxlength="255" value="<?= $e($values['trade_name']) ?>">
        </label>
    </p>
    <p>
        <label><?= $e($labels['state_registration']) ?><br>
            <input form="customer-form" type="text" name="state_registration" maxlength="20" value="<?= $e($values['state_registration']) ?>">
        </label>
        <label><input form="customer-form" type="checkbox" name="is_ie_exempt" value="1" <?= $values['is_ie_exempt'] ? 'checked' : '' ?>> <?= $e($labels['is_ie_exempt']) ?></label>
    </p>
    <p><small>Nome e sobrenome da conta são os do contato da empresa.</small></p>
</fieldset>
<?php $blocks['pj'] = ['title' => 'Pessoa jurídica', 'html' => ob_get_clean(), 'attrs' => ' data-customer-type-block="pj"' . ($type === 'pj' ? '' : ' hidden')];

// addresses: the saved ones plus a blank row that "+ Adicionar endereço" (admin.js data-add-row) copies;
// blank rows are ignored and an address left out of the post is removed
// the browser doesn't enforce "required" here (the blank row would block the save): the label keeps
// the *, AddressService checks it for every address that isn't blank
$addressFields = array_map(static fn (array $f) => ['required' => false, 'label' => $f['label'] . ($f['required'] ? ' *' : '')] + $f, $address_fields);
ob_start(); ?>
<fieldset>
    <legend>Endereços</legend>
    <div id="customer-address-rows">
<?php foreach (array_merge(array_values($addresses), [[]]) as $i => $address):
    $a = static fn (string $key) => $e($address[$key] ?? ''); ?>
        <fieldset class="customer-address" data-cep-scope>
            <input form="customer-form" type="hidden" name="addresses[<?= $i ?>][address_id]" value="<?= $a('address_id') ?>">
            <p><label><?= $e($address_labels['postcode']) ?><br><input form="customer-form" type="text" name="addresses[<?= $i ?>][postcode]" value="<?= $a('postcode') ?>" data-mask="cep" data-cep="lookup"></label></p>
            <p><label><?= $e($address_labels['street']) ?><br><input form="customer-form" type="text" name="addresses[<?= $i ?>][street]" maxlength="255" value="<?= $a('street') ?>" data-cep="logradouro"></label></p>
            <p><label><?= $e($address_labels['number']) ?><br><input form="customer-form" type="text" name="addresses[<?= $i ?>][number]" maxlength="20" size="8" value="<?= $a('number') ?>"></label></p>
            <p><label><?= $e($address_labels['district']) ?><br><input form="customer-form" type="text" name="addresses[<?= $i ?>][district]" maxlength="255" value="<?= $a('district') ?>" data-cep="bairro"></label></p>
            <p><label><?= $e($address_labels['city']) ?><br><input form="customer-form" type="text" name="addresses[<?= $i ?>][city]" maxlength="255" value="<?= $a('city') ?>" data-cep="localidade"></label></p>
            <p>
                <label><?= $e($address_labels['region']) ?><br>
                    <select form="customer-form" name="addresses[<?= $i ?>][region]" data-cep="uf">
                        <option value="">Selecione</option>
<?php foreach ($regions as $uf => $name): ?>
                        <option value="<?= $uf ?>" <?= ($address['region'] ?? '') === $uf ? 'selected' : '' ?>><?= $uf ?> — <?= $e($name) ?></option>
<?php endforeach; ?>
                    </select>
                </label>
            </p>
<?php (static function (array $schema, array $values, string $name_prefix): void {
    $form_id = 'customer-form';
    require (new \Iter\Core\View\ComponentResolver())->resolveTemplate('eav/form-fields');
})(['fields' => $addressFields], $address, "addresses[{$i}]"); ?>
            <p>
                <label><input form="customer-form" type="checkbox" name="addresses[<?= $i ?>][is_default_shipping]" value="1" data-exclusive="shipping" <?= !empty($address['is_default_shipping']) ? 'checked' : '' ?>> Padrão de entrega</label>
                <label><input form="customer-form" type="checkbox" name="addresses[<?= $i ?>][is_default_billing]" value="1" data-exclusive="billing" <?= !empty($address['is_default_billing']) ? 'checked' : '' ?>> Padrão de cobrança</label>
                <button type="button" data-clear-row>Remover</button>
            </p>
        </fieldset>
<?php endforeach; ?>
    </div>
    <button type="button" data-add-row="customer-address-rows">+ Adicionar endereço</button>
    <p><small>Linhas em branco são ignoradas. "Remover" limpa o endereço, que sai ao salvar. Sem padrão marcado, o primeiro endereço vira o padrão.</small></p>
</fieldset>
<?php $blocks['addresses'] = ['title' => 'Endereços', 'html' => ob_get_clean()];

// one block per EAV group, rendered by the eav module's template
$groupFields = [];
foreach ($schema['fields'] as $field) {
    $groupFields[$field['group'] ?? ''][] = $field;
}
foreach ($groupFields as $group => $fields) {
    $id = 'group:' . $group;
    ob_start();
    (static function (array $schema, array $values): void {
        $name_prefix = 'attributes';
        $form_id = 'customer-form';
        require (new \Iter\Core\View\ComponentResolver())->resolveTemplate('eav/form-fields');
    })(['fields' => FormLayout::sortFields($layout, $id, $fields)], $attribute_values);
    $blocks[$id] = ['title' => $group !== '' ? $group : 'Atributos', 'html' => ob_get_clean()];
}
?>
<section class="customer-form catalog-product-form">
    <h2><?= $is_edit ? 'Editar cliente' : 'Novo cliente' ?></h2>
<?php if ($is_edit && \isModuleEnabled('iter_message') && \Iter\Admin\Auth::isAllowed('Iter_Message::manage')): ?>
    <p><a href="/admin/message/view?customer_id=<?= (int) $customer_id ?>">Enviar mensagem</a></p>
<?php endif; ?>
<?php if (!$is_edit && count($sets) > 1): ?>
    <form method="get" action="/admin/customer/create">
        <label>Conjunto de atributos
            <select name="set" data-autosubmit>
    <?php foreach ($sets as $setId => $set): ?>
                <option value="<?= (int) $setId ?>" <?= $setId === $attribute_set_id ? 'selected' : '' ?>><?= $e($set['label']) ?></option>
    <?php endforeach; ?>
            </select>
        </label>
        <noscript><button type="submit">Trocar</button></noscript>
        <small>Escolha o conjunto antes de preencher: trocar recarrega o formulário e o que já foi digitado se perde.</small>
    </form>
<?php endif; ?>
    <form id="customer-form" method="post" action="/admin/customer/save" enctype="multipart/form-data">
        <?= \Iter\Core\FormKey::field() ?>
<?php if ($customer_id): ?>
        <input type="hidden" name="customer_id" value="<?= (int) $customer_id ?>">
<?php endif; ?>
        <input type="hidden" name="attribute_set_id" value="<?= (int) $attribute_set_id ?>">
    </form>

<?php (static function (array $blocks, array $layout, bool $can_arrange): void {
    $layout_url = '/admin/customerlayout';
    require (new \Iter\Core\View\ComponentResolver())->resolveTemplate('eav/form-layout');
})($blocks, $layout, $can_arrange); ?>

    <p class="product-form-submit">
        <button type="submit" form="customer-form" class="admin-button-primary">Salvar</button>
        <a href="/admin/customer/index">Cancelar</a>
    </p>
</section>
