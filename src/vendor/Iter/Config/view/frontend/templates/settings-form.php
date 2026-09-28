<?php $e = static fn ($v) => htmlspecialchars((string) $v); ?>
<section class="config-settings-form">
    <h2>Ajustes do Sistema</h2>
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
    <?php if ($website_id): ?>
        <small>Só aparecem os ajustes que podem mudar por website. Marcado "Usar o valor padrão", vale o valor do escopo Padrão.</small>
    <?php endif; ?>
    </form>
<?php endif; ?>
<?php if (empty($groups)): ?>
    <p>Nenhum módulo declara ajustes.</p>
<?php else: ?>
    <form method="post" action="/admin/config/save" autocomplete="off" enctype="multipart/form-data">
        <?= \Iter\Core\FormKey::field() ?>
        <input type="hidden" name="website_id" value="<?= (int) $website_id ?>">
    <?php foreach ($groups as $label => $fields): ?>
        <fieldset class="config-fields">
            <legend><?= $e($label) ?></legend>
        <?php foreach ($fields as $field):
            $type = $field['type'] ?? 'text';
            $name = 'config[' . $e($field['path']) . ']';
            $required = ($field['required'] ?? true) && $type !== 'password' ? 'required' : '';
        ?>
            <p class="<?= $type === 'textarea' ? 'config-field-wide' : '' ?>">
            <?php if ($type === 'image'): // outside a <label>: a click on the thumbnail must open the popup, not the file picker
                $id = 'config-' . preg_replace('/[^a-z0-9]+/', '-', $field['path']);
            ?>
                <label for="<?= $e($id) ?>"><?= $e($field['label']) ?></label><br>
                <?php if ($field['value'] !== '' && $field['value'] !== null): ?>
                <a href="<?= $e(\Iter\Core\Config::mediaUrl($field['value'])) ?>" target="_blank" data-image-popup="<?= $e($field['label']) ?>"><img class="eav-file-preview" src="<?= $e(\Iter\Core\Config::mediaUrl($field['value'])) ?>" alt="<?= $e($field['label']) ?>"></a>
                <?php endif; ?>
                <img class="eav-file-preview" alt="Imagem escolhida (salve para gravar)" title="Imagem escolhida (salve para gravar)" data-image-preview hidden>
                <br><input type="file" id="<?= $e($id) ?>" name="<?= $name ?>" accept="<?= $e(implode(',', array_keys(\Iter\Core\Config::IMAGE_TYPES))) ?>" data-image-input>
            <?php else: ?>
                <label><?= $e($field['label']) ?><br>
            <?php if ($type === 'boolean' || $type === 'select'): ?>
                    <select name="<?= $name ?>">
                <?php foreach ($type === 'boolean' ? ['1' => 'Sim', '0' => 'Não'] : $field['options'] as $value => $text): ?>
                        <option value="<?= $e($value) ?>" <?= (string) $field['value'] === (string) $value ? 'selected' : '' ?>><?= $e($text) ?></option>
                <?php endforeach; ?>
                    </select>
            <?php elseif ($type === 'textarea'): ?>
                    <textarea name="<?= $name ?>" rows="4" maxlength="2000"><?= $e($field['value']) ?></textarea>
            <?php else: ?>
                    <input type="<?= in_array($type, ['number', 'url', 'email', 'password'], true) ? $type : 'text' ?>"
                        name="<?= $name ?>"
                        value="<?= $e($field['value']) ?>"
                        <?= $type === 'password' ? 'autocomplete="new-password" placeholder="' . ($field['has_secret'] ? 'Senha salva; deixe vazio para manter' : 'Sem senha') . '"' : '' ?>
                        <?= isset($field['min']) ? 'min="' . (int) $field['min'] . '"' : '' ?>
                        <?= isset($field['max']) ? 'max="' . (int) $field['max'] . '"' : '' ?>
                        <?= isset($field['cep']) ? 'data-cep="' . $e($field['cep']) . '"' : '' ?>
                        <?= $required ?>>
            <?php endif; ?>
                </label>
            <?php endif; ?>
            <?php if ($type === 'password' && $field['has_secret']): ?>
                <label><input type="checkbox" name="config_clear[<?= $e($field['path']) ?>]" value="1"> Apagar a senha salva</label>
            <?php endif; ?>
            <?php if ($type === 'image' && (string) $field['value'] !== '' && !$field['uses_default']): ?>
                <label><input type="checkbox" name="config_clear[<?= $e($field['path']) ?>]" value="1"> Remover a imagem</label>
            <?php endif; ?>
            <?php if ($website_id): ?>
                <label><input type="checkbox" name="config_default[<?= $e($field['path']) ?>]" value="1" <?= $field['uses_default'] ? 'checked' : '' ?>> Usar o valor padrão</label>
            <?php endif; ?>
            </p>
        <?php endforeach; ?>
        </fieldset>
    <?php endforeach; ?>
        <p><button type="submit">Salvar</button></p>
    </form>
<?php endif; ?>
</section>
