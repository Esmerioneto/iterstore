<?php
use Iter\Eav\Model\FormLayout;

// "Organizar tela" bar + the blocks grid of an entity form (admin.js does the arranging).
// Takes $blocks (id => {title, html, attrs?}), $layout, $can_arrange and $layout_url
// (the module's layout controller, e.g. /admin/productlayout).
$e = static fn ($v) => htmlspecialchars((string) $v);
?>
<?php if ($can_arrange): ?>
    <div class="product-layout-actions">
        <button type="button" data-layout-toggle>Organizar tela</button>
        <span data-layout-actions hidden>
            Arraste os blocos e os campos, ou use os botões de cada um.
            <label>Colunas
                <select data-layout-columns>
                    <option value="0">Automático</option>
<?php for ($n = 1; $n <= FormLayout::MAX_COLUMNS; $n++): ?>
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
    <div class="product-layout<?= $layout['columns'] ? ' has-columns' : '' ?>"<?= $layout['columns'] ? ' style="--layout-columns: ' . (int) $layout['columns'] . '"' : '' ?> data-layout-grid data-save-url="<?= $e($layout_url) ?>/save" data-reset-url="<?= $e($layout_url) ?>/reset" data-form-key="<?= $e(\Iter\Core\FormKey::get()) ?>">
<?php foreach (FormLayout::apply($layout, $blocks) as $id => $block): ?>
        <div class="product-block<?= $block['wide'] ? ' is-wide' : '' ?>" data-block="<?= $e($id) ?>" data-title="<?= $e($block['title']) ?>"<?= $block['attrs'] ?? '' ?>>
<?= $block['html'] ?>
        </div>
<?php endforeach; ?>
    </div>
