<section class="catalog-product-form">
    <h2><?= $is_edit ? 'Editar produto' : 'Novo produto' ?></h2>
<?php if (!$is_edit && count($sets) > 1): ?>
    <form method="get" action="/admin/product/create">
        <label>Conjunto de atributos
            <select name="set">
    <?php foreach ($sets as $setId => $set): ?>
                <option value="<?= (int) $setId ?>" <?= $setId === $attribute_set_id ? 'selected' : '' ?>><?= htmlspecialchars((string) $set['label']) ?></option>
    <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Trocar</button>
        <small>Trocar o conjunto recarrega o formulário; o que já foi digitado se perde.</small>
    </form>
<?php endif; ?>
    <form method="post" action="/admin/product/save" enctype="multipart/form-data">
        <?= \Iter\Core\FormKey::field() ?>
<?php if ($product_id): ?>
        <input type="hidden" name="product_id" value="<?= (int) $product_id ?>">
<?php endif; ?>
        <input type="hidden" name="attribute_set_id" value="<?= (int) $attribute_set_id ?>">
        <p>Conjunto: <strong><?= htmlspecialchars((string) $sets[$attribute_set_id]['label']) ?></strong></p>
        <p>
            <label>SKU *<br>
                <input type="text" name="sku" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9._\-]*" value="<?= htmlspecialchars((string) $values['sku']) ?>" required>
            </label>
        </p>
        <p>
            <label>Nome *<br>
                <input type="text" name="name" maxlength="255" value="<?= htmlspecialchars((string) $values['name']) ?>" required>
            </label>
        </p>
        <p>
            <label><input type="checkbox" name="is_active" value="1" <?= $values['is_active'] ? 'checked' : '' ?>> Ativo</label>
        </p>
<?php if ($categories): ?>
        <fieldset>
            <legend>Categorias</legend>
    <?php foreach ($categories as $category): ?>
            <label style="display:block; margin-left: <?= (int) $category['level'] * 1.5 ?>em">
                <input type="checkbox" name="category_ids[]" value="<?= (int) $category['category_id'] ?>" <?= in_array((int) $category['category_id'], $category_ids, true) ? 'checked' : '' ?>>
                <?= htmlspecialchars((string) $category['name']) ?><?= $category['is_active'] ? '' : ' <small>(inativa)</small>' ?>
            </label>
    <?php endforeach; ?>
        </fieldset>
<?php endif; ?>
<?php
// the EAV fields, rendered by the eav module's template (so a module override of it applies here too)
(static function (array $schema, array $values): void {
    $name_prefix = 'attributes';
    require (new \Iter\Core\View\ComponentResolver())->resolveTemplate('eav/form-fields');
})($schema, $attribute_values);
?>
        <p>
            <button type="submit">Salvar</button>
            <a href="/admin/product/index">Cancelar</a>
        </p>
    </form>
</section>
