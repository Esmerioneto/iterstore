<section class="catalog-category-form">
    <h2><?= $is_edit ? 'Editar categoria' : 'Nova categoria' ?></h2>
    <form method="post" action="/admin/category/save" enctype="multipart/form-data">
        <?= \Iter\Core\FormKey::field() ?>
<?php if ($category_id): ?>
        <input type="hidden" name="category_id" value="<?= (int) $category_id ?>">
<?php endif; ?>
        <p>
            <label>Website *<br>
<?php if ($is_edit): ?>
                <input type="text" value="<?= htmlspecialchars((string) ($websites[$values['website_id']] ?? '')) ?>" readonly>
<?php else: ?>
                <select name="website_id" required>
    <?php foreach ($websites as $websiteId => $websiteName): ?>
                    <option value="<?= (int) $websiteId ?>" <?= (string) $values['website_id'] === (string) $websiteId ? 'selected' : '' ?>><?= htmlspecialchars((string) $websiteName) ?></option>
    <?php endforeach; ?>
                </select>
<?php endif; ?>
            </label>
            <small><?= $is_edit ? 'O website não muda depois de criada.' : 'Uma subcategoria fica no website da categoria pai.' ?></small>
        </p>
        <p>
            <label>Categoria pai<br>
                <select name="parent_id">
                    <option value="0">(nenhuma: categoria principal)</option>
<?php foreach ($parents as $parent): ?>
                    <option value="<?= (int) $parent['category_id'] ?>" <?= (int) $values['parent_id'] === (int) $parent['category_id'] ? 'selected' : '' ?>><?= str_repeat('— ', (int) $parent['level']) . htmlspecialchars((string) $parent['name']) ?><?= !$is_edit && !$parent['level'] && count($websites) > 1 ? ' (' . htmlspecialchars((string) ($websites[$parent['website_id']] ?? '')) . ')' : '' ?></option>
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
            <small>Vazia = gerada a partir do nome (ex.: "Higiene Pessoal" vira "higiene-pessoal"). Única no website.</small>
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
        <fieldset class="category-banners">
            <legend>Banners</legend>
            <small>Uma imagem, ou até <?= (int) $banner_limit ?> em carrossel no app, nesta ordem. Sugestão: 1200 × 400 px. Tocar no banner abre a categoria.</small>
<?php if ($banners): ?>
            <ol class="category-banner-list" data-sortable>
    <?php foreach ($banners as $banner): ?>
                <li class="category-banner" data-sort-item>
                    <span class="mobile-handle" data-drag-handle title="Arrastar">⠿</span>
                    <button type="button" data-move="-1" aria-label="Mover para cima">↑</button><button type="button" data-move="1" aria-label="Mover para baixo">↓</button>
                    <input type="hidden" name="banners[keep][]" value="<?= (int) $banner['banner_id'] ?>">
                    <a href="<?= htmlspecialchars($banner['url']) ?>" target="_blank" data-image-popup="Banner"><img class="category-banner-thumb" src="<?= htmlspecialchars($banner['url']) ?>" alt="Banner"></a>
                    <label><input type="checkbox" name="banners[remove][]" value="<?= (int) $banner['banner_id'] ?>"> Remover</label>
                </li>
    <?php endforeach; ?>
            </ol>
<?php endif; ?>
            <p>
                <label>Acrescentar banners<br>
                    <input type="file" name="banners_add[]" multiple accept="<?= htmlspecialchars(implode(',', $banner_accept)) ?>" data-image-input data-max-bytes="<?= (int) $banner_max_bytes ?>">
                </label>
                <small>Entram no fim da lista. No máximo <?= (int) $banner_limit ?> no total: marque "Remover" para abrir espaço.</small>
            </p>
        </fieldset>
        <p>
            <button type="submit">Salvar</button>
            <a href="/admin/category/index">Cancelar</a>
        </p>
    </form>
</section>
