<?php
use Iter\Eav\Model\FormLayout;

// The form is split into blocks the admin can arrange ("Organizar tela", admin.js).
// The <form> itself is empty: fields point to it with form="product-form", so a
// block with its own form (the stock panel, a layout child) can sit between them.
$e = static fn ($v) => htmlspecialchars((string) $v);
$blocks = [];

ob_start(); ?>
<fieldset>
    <legend>Dados do produto</legend>
    <p>Conjunto: <strong><?= $e($sets[$attribute_set_id]['label']) ?></strong></p>
    <p>
        <label>SKU *<br>
            <input form="product-form" type="text" name="sku" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9._\-]*" value="<?= $e($values['sku']) ?>" required>
        </label>
    </p>
    <p>
        <label>Nome *<br>
            <input form="product-form" type="text" name="name" maxlength="255" value="<?= $e($values['name']) ?>" required>
        </label>
    </p>
    <p>
        <label><input form="product-form" type="checkbox" name="is_active" value="1" <?= $values['is_active'] ? 'checked' : '' ?>> Ativo</label>
    </p>
</fieldset>
<?php $blocks['base'] = ['title' => 'Dados do produto', 'html' => ob_get_clean()];

ob_start(); ?>
<fieldset>
    <legend>Categorias <span aria-hidden="true">*</span></legend>
    <?php if (!$categories): ?>
    <p>Nenhuma categoria cadastrada. <a href="/admin/category/index">Cadastre uma categoria</a> antes de salvar o produto.</p>
    <?php endif; ?>
    <?php foreach ($categories as $category): ?>
    <label style="display:block; margin-left: <?= (int) $category['level'] * 1.5 ?>em">
        <input form="product-form" type="checkbox" name="category_ids[]" data-required-group value="<?= (int) $category['category_id'] ?>" <?= in_array((int) $category['category_id'], $category_ids, true) ? 'checked' : '' ?>>
        <?= $e($category['name']) ?><?= $category['is_active'] ? '' : ' <small>(inativa)</small>' ?>
    </label>
    <?php endforeach; ?>
</fieldset>
<?php $blocks['categories'] = ['title' => 'Categorias', 'html' => ob_get_clean()];

// one block per EAV group, rendered by the eav module's template (so a module override of it applies here too)
$groups = [];
foreach ($schema['fields'] as $field) {
    $groups[$field['group'] ?? ''][] = $field;
}
foreach ($groups as $group => $fields) {
    $id = 'group:' . $group;
    ob_start();
    (static function (array $schema, array $values): void {
        $name_prefix = 'attributes';
        $form_id = 'product-form';
        require (new \Iter\Core\View\ComponentResolver())->resolveTemplate('eav/form-fields');
    })(['entity_type' => 'product', 'fields' => FormLayout::sortFields($layout, $id, $fields)], $attribute_values);
    $blocks[$id] = ['title' => $group !== '' ? $group : 'Atributos', 'html' => ob_get_clean()];
}

// blocks from other modules' layout (e.g. Inventory's stock panel); empty ones are skipped
foreach ($children ?? [] as $name => $html) {
    if (trim($html) !== '') {
        $blocks[$name] = ['title' => preg_match('#<h2[^>]*>(.*?)</h2>#s', $html, $m) ? strip_tags($m[1]) : $name, 'html' => $html];
    }
}
?>
<section class="catalog-product-form">
    <h2><?= $is_edit ? 'Editar produto' : 'Novo produto' ?></h2>
<?php if (!$is_edit && count($sets) > 1): ?>
    <form method="get" action="/admin/product/create">
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
    <form id="product-form" method="post" action="/admin/product/save" enctype="multipart/form-data">
        <?= \Iter\Core\FormKey::field() ?>
<?php if ($product_id): ?>
        <input type="hidden" name="product_id" value="<?= (int) $product_id ?>">
<?php endif; ?>
        <input type="hidden" name="attribute_set_id" value="<?= (int) $attribute_set_id ?>">
    </form>

<?php (static function (array $blocks, array $layout, bool $can_arrange): void {
    $layout_url = '/admin/productlayout';
    require (new \Iter\Core\View\ComponentResolver())->resolveTemplate('eav/form-layout');
})($blocks, $layout, $can_arrange); ?>

    <p class="product-form-submit">
        <button type="submit" form="product-form">Salvar</button>
        <a href="/admin/product/index">Cancelar</a>
    </p>
</section>
