<?php
$e = static fn ($v) => htmlspecialchars((string) $v);
// a chosen product, or one waiting in the category's pool (its input disabled, so it isn't posted)
$productItem = static function (int $categoryId, array $p, bool $chosen) use ($e): string {
    $image = $p['image'] !== null
        ? '<img class="eav-file-preview mobile-thumb" src="' . $e(\Iter\Eav\Model\ValueService::MEDIA_URL . $p['image']) . '" alt="">'
        : '<img class="eav-file-preview mobile-thumb eav-file-placeholder" src="/imagem/logo.png" alt="Sem imagem">';

    return '<li class="mobile-product" data-sort-item data-product-id="' . (int) $p['product_id'] . '" data-active="' . (int) $p['is_active'] . '">'
        . '<span class="mobile-handle" data-drag-handle title="Arrastar">⠿</span>'
        . '<button type="button" data-move="-1" aria-label="Mover para cima">↑</button><button type="button" data-move="1" aria-label="Mover para baixo">↓</button>'
        . '<input type="hidden" name="categories[' . $categoryId . '][product_ids][]" value="' . (int) $p['product_id'] . '"' . ($chosen ? '' : ' disabled') . '>'
        . $image . '<code>' . $e($p['sku']) . '</code> <span class="mobile-product-name">' . $e($p['name']) . '</span>'
        . ($p['salable'] !== null && $p['salable'] <= 0 ? ' <small>esgotado</small>' : '')
        . ($p['is_active'] ? '' : ' <small>inativo, não aparece no app</small>')
        . ' <small data-mobile-after hidden>depois do "mostrar mais"</small>'
        . '<button type="button" data-mobile-remove aria-label="Tirar dos escolhidos">✕</button></li>';
};
?>
<section class="mobile-order">
    <h2>Mobile — ordem do app</h2>
<?php if (count($websites) > 1): ?>
    <form method="get" action="/admin/mobile/index">
        <label>Website
            <select name="website_id" data-autosubmit>
    <?php foreach ($websites as $id => $name): ?>
                <option value="<?= (int) $id ?>" <?= (int) $id === $website_id ? 'selected' : '' ?>><?= $e($name) ?></option>
    <?php endforeach; ?>
            </select>
        </label>
    </form>
<?php endif; ?>
    <form method="post" action="/admin/mobile/save" data-mobile-order>
        <?= \Iter\Core\FormKey::field() ?>
        <input type="hidden" name="website_id" value="<?= (int) $website_id ?>">
        <p>
            <label><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>> Usar esta ordem no app</label><br>
            <small>Desmarcado, o app mostra todas as categorias ativas na ordem do catálogo, com os <?= \Iter\Catalog\Model\MobileService::DEFAULT_PER_CATEGORY ?> produtos mais novos de cada.</small>
        </p>
<?php if (!$categories): ?>
        <p>Nenhuma categoria neste website.</p>
<?php else: ?>
        <p><small>Arraste ⠿ ou use ↑ ↓ para mudar a ordem. Só as categorias com "Mostrar no app" marcado aparecem no app. Em cada uma, os escolhidos à mão vêm primeiro e os mais novos completam a quantidade; o resto aparece no "mostrar mais".</small></p>
        <ol class="mobile-categories" data-sortable>
    <?php foreach ($categories as $c): ?>
        <?php
            $id = (int) $c['category_id'];
            $chosen = array_intersect_key($c['products'], array_flip($c['chosen']));
            $chosen = array_map(static fn ($pid) => $chosen[$pid], $c['chosen']);
            $pool = array_diff_key($c['products'], array_flip($c['chosen']));
            $activeCount = count(array_filter($c['products'], static fn ($p) => $p['is_active']));
        ?>
            <li class="mobile-category" data-sort-item data-mobile-category>
                <div class="mobile-bar">
                    <span class="mobile-handle" data-drag-handle title="Arrastar">⠿</span>
                    <button type="button" data-move="-1" aria-label="Mover para cima">↑</button><button type="button" data-move="1" aria-label="Mover para baixo">↓</button>
                    <strong class="mobile-category-name"><?= $e($c['path']) ?></strong>
                    <label><input type="checkbox" name="categories[<?= $id ?>][visible]" value="1" <?= $c['visible'] ? 'checked' : '' ?> data-mobile-visible> Mostrar no app</label>
                    <?= $c['active'] ? '' : '<small>(inativa, não aparece no app)</small>' ?>
                    <small data-mobile-summary><?= $c['visible'] ? '' : 'fora do app · ' ?><?= $activeCount ?> produtos · exibe <?= (int) $c['per_category'] ?> · <?= count($chosen) ?> escolhidos</small>
                </div>
                <details>
                    <summary>Produtos</summary>
                    <p><label>Exibir de início <input type="number" name="categories[<?= $id ?>][per_category]" value="<?= (int) $c['per_category'] ?>" min="1" max="<?= \Iter\Catalog\Model\MobileService::MAX_PER_CATEGORY ?>" required data-mobile-limit> produtos</label></p>
                    <p data-mobile-count>Escolhidos à mão (<?= count($chosen) ?> de <?= (int) $c['per_category'] ?>)</p>
                    <ol class="mobile-products" data-sortable data-mobile-chosen>
        <?php foreach ($chosen as $p): ?>
                        <?= $productItem($id, $p, true) ?>
        <?php endforeach; ?>
                    </ol>
                    <p><small data-mobile-note></small></p>
                    <p>
                        <label>Adicionar
                            <select data-mobile-add-select>
                                <option value="">Escolha um produto desta categoria…</option>
        <?php foreach ($c['products'] as $p): ?>
                                <option value="<?= (int) $p['product_id'] ?>" <?= isset($pool[$p['product_id']]) ? '' : 'hidden disabled' ?>><?= $e($p['sku'] . ' — ' . $p['name']) ?></option>
        <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="button" data-mobile-add>Adicionar</button>
                    </p>
                    <ol hidden data-mobile-pool>
        <?php foreach ($pool as $p): ?>
                        <?= $productItem($id, $p, false) ?>
        <?php endforeach; ?>
                    </ol>
                </details>
            </li>
    <?php endforeach; ?>
        </ol>
<?php endif; ?>
        <p><button type="submit">Salvar</button></p>
    </form>
</section>
