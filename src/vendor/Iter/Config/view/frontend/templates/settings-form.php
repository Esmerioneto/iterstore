<?php
$e = static fn ($v) => htmlspecialchars((string) $v);
$total = array_sum(array_column($groups, 'count'));
// one field: label, the control (with its unit), the hint below and the scope's extra choices
$renderField = static function (array $field) use ($e, $website_id): void {
    $type = $field['type'] ?? 'text';
    $name = 'config[' . $e($field['path']) . ']';
    $required = ($field['required'] ?? true) && $type !== 'password' ? 'required' : '';
    $hint = isset($field['hint']) ? '<small>' . $e($field['hint']) . '</small>' : '';
    $extras = static function () use ($field, $type, $e, $website_id): void {
        if ($type === 'password' && $field['has_secret']): ?>
                <label class="config-check"><input type="checkbox" name="config_clear[<?= $e($field['path']) ?>]" value="1"> Apagar a senha salva</label>
        <?php endif;
        if ($type === 'image' && (string) $field['value'] !== '' && !$field['uses_default']): ?>
                <label class="config-check"><input type="checkbox" name="config_clear[<?= $e($field['path']) ?>]" value="1"> Remover a imagem</label>
        <?php endif;
        if ($website_id): ?>
                <label class="config-check"><input type="checkbox" name="config_default[<?= $e($field['path']) ?>]" value="1" <?= $field['uses_default'] ? 'checked' : '' ?>> Usar o valor padrão</label>
        <?php endif;
    };
    if ($type === 'boolean'): // a switch row; the hidden "0" is what's posted when it's off ?>
            <div class="config-switch" data-config-item>
                <label><span><b><?= $e($field['label']) ?></b><?= $hint ?></span>
                    <input type="hidden" name="<?= $name ?>" value="0">
                    <input type="checkbox" class="admin-switch" name="<?= $name ?>" value="1" <?= (string) $field['value'] === '1' ? 'checked' : '' ?>>
                </label>
                <?php $extras(); ?>
            </div>
    <?php return;
    endif; ?>
            <div class="config-field<?= $type === 'textarea' ? ' config-field-wide' : '' ?>" data-config-item>
    <?php if ($type === 'image'): // outside a <label>: a click on the thumbnail must open the popup, not the file picker
        $id = 'config-' . preg_replace('/[^a-z0-9]+/', '-', $field['path']);
    ?>
                <label for="<?= $e($id) ?>"><span class="config-label"><?= $e($field['label']) ?></span></label>
        <?php if ($field['value'] !== '' && $field['value'] !== null): ?>
                <a href="<?= $e(\Iter\Core\Config::mediaUrl($field['value'])) ?>" target="_blank" data-image-popup="<?= $e($field['label']) ?>"><img class="eav-file-preview" src="<?= $e(\Iter\Core\Config::mediaUrl($field['value'])) ?>" alt="<?= $e($field['label']) ?>"></a>
        <?php endif; ?>
                <img class="eav-file-preview" alt="Imagem escolhida (salve para gravar)" title="Imagem escolhida (salve para gravar)" data-image-preview hidden>
                <input type="file" id="<?= $e($id) ?>" name="<?= $name ?>" accept="<?= $e(implode(',', array_keys(\Iter\Core\Config::IMAGE_TYPES))) ?>" data-image-input data-max-bytes="<?= min(\Iter\Core\Config::IMAGE_MAX_BYTES, \Iter\Core\Config::uploadMaxBytes()) ?>">
    <?php else: ?>
                <label><span class="config-label"><?= $e($field['label']) ?></span>
        <?php if ($type === 'select'): ?>
                    <select name="<?= $name ?>">
            <?php foreach ($field['options'] as $value => $text): ?>
                        <option value="<?= $e($value) ?>" <?= (string) $field['value'] === (string) $value ? 'selected' : '' ?>><?= $e($text) ?></option>
            <?php endforeach; ?>
                    </select>
        <?php elseif ($type === 'textarea'): ?>
                    <textarea name="<?= $name ?>" rows="3" maxlength="2000"><?= $e($field['value']) ?></textarea>
        <?php else: ?>
                    <span class="config-input<?= isset($field['unit']) ? ' has-unit' : '' ?><?= isset($field['prefix']) ? ' has-prefix' : '' ?><?= $type === 'password' && $field['has_secret'] ? ' has-secret' : '' ?>">
            <?php if (isset($field['prefix'])): ?><span class="config-affix"><?= $e($field['prefix']) ?></span><?php endif; ?>
                    <input type="<?= in_array($type, ['number', 'url', 'email', 'password'], true) ? $type : 'text' ?>"
                        name="<?= $name ?>"
                        value="<?= $e($field['value']) ?>"
                        <?= $type === 'password' ? 'autocomplete="new-password" placeholder="' . ($field['has_secret'] ? 'Deixe vazio para manter' : 'Sem senha') . '"' : '' ?>
                        <?= isset($field['min']) ? 'min="' . (int) $field['min'] . '"' : '' ?>
                        <?= isset($field['max']) ? 'max="' . (int) $field['max'] . '"' : '' ?>
                        <?= isset($field['cep']) ? 'data-cep="' . $e($field['cep']) . '"' : '' ?>
                        <?= isset($field['mask']) ? 'data-mask="' . $e($field['mask']) . '"' : '' ?>
                        <?= $required ?>>
            <?php if (isset($field['unit'])): ?><span class="config-affix"><?= $e($field['unit']) ?></span><?php endif; ?>
            <?php if ($type === 'password' && $field['has_secret']): ?><span class="config-chip is-on">salva</span><?php endif; ?>
                    </span>
        <?php endif; ?>
                </label>
    <?php endif; ?>
                <?= $hint ?>
                <?php $extras(); ?>
            </div>
<?php
};
?>
<section class="config-settings-form">
    <div class="config-head">
        <div>
            <h2>Ajustes do Sistema</h2>
            <p><?= count($groups) ?> grupo(s) · <?= (int) $total ?> ajuste(s). Clique num grupo para abrir.</p>
        </div>
<?php if ($groups): ?>
        <div class="config-tools">
            <input type="search" placeholder="Buscar ajuste (ex.: pix, senha, smtp)" aria-label="Buscar ajuste" data-config-search>
            <button type="button" data-config-open="1">Abrir todos</button>
            <button type="button" data-config-open="0">Fechar todos</button>
        </div>
<?php endif; ?>
    </div>
<?php if ($websites): ?>
    <form method="get" action="/admin/config/index" class="config-scope">
        <label>Escopo
            <select name="website" data-autosubmit>
                <option value="0">Padrão (todos os websites)</option>
    <?php foreach ($websites as $id => $name): ?>
                <option value="<?= (int) $id ?>" <?= $id === $website_id ? 'selected' : '' ?>>Website: <?= $e($name) ?></option>
    <?php endforeach; ?>
            </select>
        </label>
        <noscript><button type="submit">Trocar</button></noscript>
        <small><?= $website_id ? 'Só aparecem os ajustes que podem mudar por website. Marcado "Usar o valor padrão", vale o valor do escopo Padrão.' : 'Escolha um website para mudar só os ajustes dele.' ?></small>
    </form>
<?php endif; ?>
<?php if (empty($groups)): ?>
    <p>Nenhum módulo declara ajustes.</p>
<?php else: ?>
    <form method="post" action="/admin/config/save" autocomplete="off" enctype="multipart/form-data" class="config-form" data-config-form>
        <?= \Iter\Core\FormKey::field() ?>
        <input type="hidden" name="website_id" value="<?= (int) $website_id ?>">
    <?php $section = null;
    foreach ($groups as $i => $group):
        if ($group['section'] !== $section): $section = $group['section']; ?>
        <h3 class="config-section"><?= $e($section) ?></h3>
        <?php endif; ?>
        <details class="config-group" id="<?= $e($group['id']) ?>" <?= $i === 0 ? 'open' : '' ?>>
            <summary>
                <span class="config-icon" aria-hidden="true"><?= $e($group['icon']) ?></span>
                <span class="config-title"><b><?= $e($group['label']) ?></b><?php if ($group['description'] !== ''): ?><small><?= $e($group['description']) ?></small><?php endif; ?></span>
                <span class="config-chips">
        <?php foreach ($group['chips'] as $chip): ?>
                    <span class="config-chip <?= $chip['on'] ? 'is-on' : 'is-off' ?>"><?= $e($chip['text']) ?></span>
        <?php endforeach; ?>
                    <span class="config-chip"><?= (int) $group['count'] ?> ajuste(s)</span>
                </span>
            </summary>
            <div class="config-body">
        <?php foreach ($group['blocks'] as $block => $fields):
            if ($block === ''): ?>
                <div class="config-fields">
                <?php foreach ($fields as $field) { $renderField($field); } ?>
                </div>
            <?php continue;
            endif;
            // the block's own switch (block_toggle) goes in its header; off, the fields below are dimmed
            $toggle = null;
            foreach ($fields as $k => $field) {
                if (!empty($field['block_toggle']) && ($field['type'] ?? '') === 'boolean') {
                    $toggle = $field;
                    unset($fields[$k]);
                    break;
                }
            } ?>
                <div class="config-block">
                <?php if ($toggle): $renderField($toggle); else: ?>
                    <div class="config-block-head"><b><?= $e($block) ?></b></div>
                <?php endif; ?>
                    <div class="config-fields">
                    <?php foreach ($fields as $field) { $renderField($field); } ?>
                    </div>
                </div>
        <?php endforeach; ?>
            </div>
        </details>
    <?php endforeach; ?>
        <p class="config-empty" data-config-empty hidden>Nenhum ajuste encontrado.</p>
        <div class="config-savebar" data-config-savebar>
            <span data-config-dirty-text></span>
            <button type="reset" data-config-discard>Descartar</button>
            <button type="submit">Salvar</button>
        </div>
    </form>
<?php endif; ?>
</section>
