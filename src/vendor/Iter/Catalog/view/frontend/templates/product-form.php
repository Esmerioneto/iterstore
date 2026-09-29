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
    <p>Conjunto: <strong><?= $e($sets[$attribute_set_id]['label']) ?></strong><?= $type === 'configurable' ? ' — produto com grade' : '' ?></p>
<?php if ($parent): ?>
    <p>Variação de <a href="/admin/product/edit?id=<?= (int) $parent['product_id'] ?>"><?= $e($parent['sku'] . ' — ' . $parent['name']) ?></a>:
        <?= $e(implode(', ', array_map(static fn ($a) => $a['label'] . ': ' . (array_column($a['options'], 'label', 'value')[$attribute_values[$a['code']] ?? ''] ?? ''), $grid))) ?>.
        <small>Nome, websites e categorias vêm do produto pai.</small></p>
<?php endif; ?>
    <p>
        <label>SKU *<br>
            <input form="product-form" type="text" name="sku" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9._\-]*" value="<?= $e($values['sku']) ?>" required>
        </label>
    </p>
    <p>
        <label>Nome *<br>
            <input form="product-form" type="text" name="name" maxlength="255" value="<?= $e($values['name']) ?>" required <?= $parent ? 'readonly' : '' ?>>
        </label>
    </p>
    <p>
        <label><input form="product-form" type="checkbox" name="is_active" value="1" <?= $values['is_active'] ? 'checked' : '' ?>> Ativo</label>
    </p>
<?php if (!$is_edit && $type === 'configurable'): ?>
    <fieldset>
        <legend>Atributos da grade *</legend>
    <?php foreach ($variant_candidates as $field): ?>
        <label style="display:block"><input form="product-form" type="checkbox" name="variant_attributes[]" value="<?= $e($field['name']) ?>" <?= in_array($field['name'], $variant_attributes, true) ? 'checked' : '' ?>> <?= $e($field['label']) ?></label>
    <?php endforeach; ?>
    <?php if (!$variant_candidates): ?>
        <p>O conjunto não tem atributos marcados "Usar na grade": em Catálogo → Atributos, marque a opção num atributo do tipo Seleção (ex.: Cor, Tamanho) e inclua-o no conjunto.</p>
    <?php endif; ?>
        <small>De 1 a 3 (ex.: Cor e Tamanho). Não mudam depois de salvar.</small>
    </fieldset>
<?php endif; ?>
</fieldset>
<?php $blocks['base'] = ['title' => 'Dados do produto', 'html' => ob_get_clean()];

ob_start(); ?>
<fieldset>
    <legend>Websites <span aria-hidden="true">*</span></legend>
    <?php foreach ($websites as $websiteId => $websiteName): ?>
    <label style="display:block">
        <input form="product-form" type="checkbox" name="website_ids[]" data-required-group value="<?= (int) $websiteId ?>" <?= in_array((int) $websiteId, $website_ids, true) ? 'checked' : '' ?><?= $parent ? ' disabled' : '' ?>>
        <?= $e($websiteName) ?>
    </label>
    <?php endforeach; ?>
    <small>Cada website marcado precisa de ao menos uma categoria dele.</small>
</fieldset>
<?php $blocks['websites'] = ['title' => 'Websites', 'html' => ob_get_clean()];

ob_start(); ?>
<fieldset>
    <legend>Categorias <span aria-hidden="true">*</span></legend>
    <?php if (!$categories): ?>
    <p>Nenhuma categoria cadastrada. <a href="/admin/category/index">Cadastre uma categoria</a> antes de salvar o produto.</p>
    <?php endif; ?>
    <?php foreach ($categories as $category): ?>
    <label style="display:block; margin-left: <?= (int) $category['level'] * 1.5 ?>em">
        <input form="product-form" type="checkbox" name="category_ids[]" data-required-group data-category-parent="<?= (int) $category['parent_id'] ?>" value="<?= (int) $category['category_id'] ?>" <?= in_array((int) $category['category_id'], $category_ids, true) ? 'checked' : '' ?><?= $parent ? ' disabled' : '' ?>>
        <?= $e($category['name']) ?><?= $category['is_active'] ? '' : ' <small>(inativa)</small>' ?><?= !$category['level'] && count($websites) > 1 ? ' <small>(' . $e($websites[$category['website_id']] ?? '') . ')</small>' : '' ?>
    </label>
    <?php endforeach; ?>
</fieldset>
<?php $blocks['categories'] = ['title' => 'Categorias', 'html' => ob_get_clean()];

// a configurable product's variants: one row each, plus the options to generate the missing combinations
if ($type === 'configurable' && $is_edit) {
    ob_start(); ?>
<fieldset>
    <legend>Grade</legend>
    <?php foreach ($grid as $attribute): ?>
    <p><?= $e($attribute['label']) ?>:
        <?php foreach ($attribute['options'] as $option): ?>
        <label><input form="product-form" type="checkbox" name="grade[<?= $e($attribute['code']) ?>][]" value="<?= $e($option['value']) ?>" <?= in_array($option['value'], array_column(array_column($variants, 'options'), $attribute['code']), true) ? 'checked' : '' ?>> <?= $e($option['label']) ?></label>
        <?php endforeach; ?>
    </p>
    <?php endforeach; ?>
    <p><label>Preço das novas variações <input form="product-form" type="text" name="grade_price" inputmode="decimal" size="10"></label>
        <button type="submit" form="product-form">Gerar variações</button>
        <small>Cria as combinações marcadas que ainda não existem, com o SKU sugerido; o produto é salvo junto.</small></p>
    <table>
        <thead><tr><th>Variação</th><th>SKU</th><th>Preço</th><th>Ativo</th><th>Remover</th><th></th></tr></thead>
        <tbody>
    <?php foreach ($variants as $variant): $n = 'variants[' . $variant['product_id'] . ']'; ?>
            <tr>
                <td><?= $e(implode(' / ', array_map(static fn ($a) => array_column($a['options'], 'label', 'value')[$variant['options'][$a['code']]] ?? '', $grid))) ?></td>
                <td><input form="product-form" type="text" name="<?= $n ?>[sku]" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9._\-]*" value="<?= $e($variant['sku']) ?>" required></td>
                <td><input form="product-form" type="text" name="<?= $n ?>[price]" inputmode="decimal" size="10" value="<?= $e($variant['price'] ?? '') ?>"></td>
                <td><input form="product-form" type="checkbox" name="<?= $n ?>[is_active]" value="1" <?= $variant['is_active'] ? 'checked' : '' ?>></td>
                <td><input form="product-form" type="checkbox" name="<?= $n ?>[remove]" value="1"></td>
                <td><a href="/admin/product/edit?id=<?= (int) $variant['product_id'] ?>">Editar</a></td>
            </tr>
    <?php endforeach; ?>
    <?php if (!$variants): ?>
            <tr><td colspan="6">Nenhuma variação ainda: marque as opções acima e clique em "Gerar variações".</td></tr>
    <?php endif; ?>
        </tbody>
    </table>
    <small>Fotos e descrição de cada variação ficam em "Editar". Uma variação removida perde o estoque dela.</small>
</fieldset>
<?php $blocks['grid'] = ['title' => 'Grade', 'html' => ob_get_clean()];
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
<?php if (!$is_edit): ?>
    <form method="get" action="/admin/product/create">
    <?php if (count($sets) > 1): ?>
        <label>Conjunto de atributos
            <select name="set" data-autosubmit>
        <?php foreach ($sets as $setId => $set): ?>
                <option value="<?= (int) $setId ?>" <?= $setId === $attribute_set_id ? 'selected' : '' ?>><?= $e($set['label']) ?></option>
        <?php endforeach; ?>
            </select>
        </label>
    <?php else: ?>
        <input type="hidden" name="set" value="<?= (int) $attribute_set_id ?>">
    <?php endif; ?>
        <label>Tipo
            <select name="type" data-autosubmit>
                <option value="simple">Simples</option>
                <option value="configurable" <?= $type === 'configurable' ? 'selected' : '' ?>>Com grade (cor, tamanho…)</option>
            </select>
        </label>
        <noscript><button type="submit">Trocar</button></noscript>
        <small>Escolha o conjunto e o tipo antes de preencher: trocar recarrega o formulário e o que já foi digitado se perde.</small>
    </form>
<?php endif; ?>
    <form id="product-form" method="post" action="/admin/product/save" enctype="multipart/form-data">
        <?= \Iter\Core\FormKey::field() ?>
<?php if ($product_id): ?>
        <input type="hidden" name="product_id" value="<?= (int) $product_id ?>">
<?php endif; ?>
        <input type="hidden" name="attribute_set_id" value="<?= (int) $attribute_set_id ?>">
<?php if (!$is_edit): ?>
        <input type="hidden" name="type" value="<?= $e($type) ?>">
<?php endif; ?>
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
