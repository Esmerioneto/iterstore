<?php
use Iter\Catalog\Model\ProductFormLayout;

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

if ($categories) {
    ob_start(); ?>
<fieldset>
    <legend>Categorias</legend>
    <?php foreach ($categories as $category): ?>
    <label style="display:block; margin-left: <?= (int) $category['level'] * 1.5 ?>em">
        <input form="product-form" type="checkbox" name="category_ids[]" value="<?= (int) $category['category_id'] ?>" <?= in_array((int) $category['category_id'], $category_ids, true) ? 'checked' : '' ?>>
        <?= $e($category['name']) ?><?= $category['is_active'] ? '' : ' <small>(inativa)</small>' ?>
    </label>
    <?php endforeach; ?>
</fieldset>
    <?php $blocks['categories'] = ['title' => 'Categorias', 'html' => ob_get_clean()];
}

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
    })(['fields' => ProductFormLayout::sortFields($layout, $id, $fields)], $attribute_values);
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
    <form id="product-form" method="post" action="/admin/product/save" enctype="multipart/form-data">
        <?= \Iter\Core\FormKey::field() ?>
<?php if ($product_id): ?>
        <input type="hidden" name="product_id" value="<?= (int) $product_id ?>">
<?php endif; ?>
        <input type="hidden" name="attribute_set_id" value="<?= (int) $attribute_set_id ?>">
    </form>

<?php if ($can_arrange): ?>
    <div class="product-layout-actions">
        <button type="button" data-layout-toggle>Organizar tela</button>
        <span data-layout-actions hidden>
            Arraste os blocos e os campos, ou use os botões de cada um.
            <label>Colunas
                <select data-layout-columns>
                    <option value="0">Automático</option>
<?php for ($n = 1; $n <= ProductFormLayout::MAX_COLUMNS; $n++): ?>
                    <option value="<?= $n ?>" <?= $layout['columns'] === $n ? 'selected' : '' ?>><?= $n ?></option>
<?php endfor; ?>
                </select>
            </label>
            <button type="button" data-layout-save>Salvar layout</button>
            <button type="button" data-layout-cancel>Cancelar</button>
            <button type="button" data-layout-reset>Restaurar padrão</button>
        </span>
        <span data-layout-status role="status"></span>
    </div>
<?php endif; ?>
    <div class="product-layout<?= $layout['columns'] ? ' has-columns' : '' ?>"<?= $layout['columns'] ? ' style="--layout-columns: ' . (int) $layout['columns'] . '"' : '' ?> data-layout-grid data-save-url="/admin/productlayout/save" data-reset-url="/admin/productlayout/reset" data-form-key="<?= $e(\Iter\Core\FormKey::get()) ?>">
<?php foreach (ProductFormLayout::apply($layout, $blocks) as $id => $block): ?>
        <div class="product-block<?= $block['wide'] ? ' is-wide' : '' ?>" data-block="<?= $e($id) ?>" data-title="<?= $e($block['title']) ?>">
<?= $block['html'] ?>
        </div>
<?php endforeach; ?>
    </div>

    <p class="product-form-submit">
        <button type="submit" form="product-form">Salvar</button>
        <a href="/admin/product/index">Cancelar</a>
    </p>
</section>
