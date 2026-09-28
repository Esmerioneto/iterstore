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
        <label>Tipo *<br>
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
        <label>E-mail *<br>
            <input form="customer-form" type="email" name="email" maxlength="255" value="<?= $e($values['email']) ?>" data-mask="email" required>
        </label>
    </p>
    <p>
        <label>Nome *<br>
            <input form="customer-form" type="text" name="firstname" maxlength="255" value="<?= $e($values['firstname']) ?>" required>
        </label>
    </p>
    <p>
        <label>Sobrenome *<br>
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
        <label>Data de nascimento<br>
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
        <label>Razão social *<br>
            <input form="customer-form" type="text" name="company_name" maxlength="255" value="<?= $e($values['company_name']) ?>" required>
        </label>
    </p>
    <p>
        <label>Nome fantasia<br>
            <input form="customer-form" type="text" name="trade_name" maxlength="255" value="<?= $e($values['trade_name']) ?>">
        </label>
    </p>
    <p>
        <label>Inscrição estadual<br>
            <input form="customer-form" type="text" name="state_registration" maxlength="20" value="<?= $e($values['state_registration']) ?>">
        </label>
        <label><input form="customer-form" type="checkbox" name="is_ie_exempt" value="1" <?= $values['is_ie_exempt'] ? 'checked' : '' ?>> Isento</label>
    </p>
    <p><small>Nome e sobrenome da conta são os do contato da empresa.</small></p>
</fieldset>
<?php $blocks['pj'] = ['title' => 'Pessoa jurídica', 'html' => ob_get_clean(), 'attrs' => ' data-customer-type-block="pj"' . ($type === 'pj' ? '' : ' hidden')];

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
<section class="customer-form">
    <h2><?= $is_edit ? 'Editar cliente' : 'Novo cliente' ?></h2>
<?php if (!$is_edit && count($sets) > 1): ?>
    <form method="get" action="/admin/customer/create">
        <label>Conjunto de atributos
            <select name="set">
    <?php foreach ($sets as $setId => $set): ?>
                <option value="<?= (int) $setId ?>" <?= $setId === $attribute_set_id ? 'selected' : '' ?>><?= $e($set['label']) ?></option>
    <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Trocar</button>
        <small>Trocar o conjunto recarrega o formulário; o que já foi digitado se perde.</small>
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
        <button type="submit" form="customer-form">Salvar</button>
        <a href="/admin/customer/index">Cancelar</a>
    </p>
</section>
