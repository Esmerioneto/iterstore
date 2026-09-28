<section class="catalog-category-form">
    <h2><?= $is_edit ? 'Editar categoria' : 'Nova categoria' ?></h2>
    <form method="post" action="/admin/category/save" enctype="multipart/form-data">
        <?= \Iter\Core\FormKey::field() ?>
<?php if ($category_id): ?>
        <input type="hidden" name="category_id" value="<?= (int) $category_id ?>">
<?php endif; ?>
        <p>
            <label>Categoria pai<br>
                <select name="parent_id">
                    <option value="0">(nenhuma: categoria principal)</option>
<?php foreach ($parents as $parent): ?>
                    <option value="<?= (int) $parent['category_id'] ?>" <?= (int) $values['parent_id'] === (int) $parent['category_id'] ? 'selected' : '' ?>><?= str_repeat('— ', (int) $parent['level']) . htmlspecialchars((string) $parent['name']) ?></option>
<?php endforeach; ?>
                </select>
            </label>
        </p>
        <p>
            <label>Nome *<br>
                <input type="text" name="name" maxlength="255" value="<?= htmlspecialchars((string) $values['name']) ?>" required>
            </label>
        </p>
        <p>
            <label>Chave de URL<br>
                <input type="text" name="url_key" maxlength="255" value="<?= htmlspecialchars((string) $values['url_key']) ?>">
            </label>
            <small>Vazia = gerada a partir do nome (ex.: "Higiene Pessoal" vira "higiene-pessoal"). Única no catálogo.</small>
        </p>
        <p>
            <label>Posição<br>
                <input type="number" name="position" min="0" step="1" value="<?= (int) $values['position'] ?>">
            </label>
            <small>Ordem entre as categorias do mesmo nível (menor primeiro).</small>
        </p>
        <p>
            <label><input type="checkbox" name="is_active" value="1" <?= $values['is_active'] ? 'checked' : '' ?>> Ativa</label>
        </p>
<?php
// the EAV fields of the category entity's default set
(static function (array $schema, array $values): void {
    $name_prefix = 'attributes';
    require (new \Iter\Core\View\ComponentResolver())->resolveTemplate('eav/form-fields');
})($schema, $attribute_values);
?>
        <p>
            <button type="submit">Salvar</button>
            <a href="/admin/category/index">Cancelar</a>
        </p>
    </form>
</section>
