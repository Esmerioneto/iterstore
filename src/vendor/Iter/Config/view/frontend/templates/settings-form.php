<section class="config-settings-form">
    <h2>Ajustes do Sistema</h2>
<?php if (empty($groups)): ?>
    <p>Nenhum módulo declara ajustes.</p>
<?php else: ?>
    <form method="post" action="/admin/config/save">
        <?= \Iter\Core\FormKey::field() ?>
    <?php foreach ($groups as $label => $fields): ?>
        <fieldset>
            <legend><?= htmlspecialchars($label) ?></legend>
        <?php foreach ($fields as $field): ?>
            <p>
                <label><?= htmlspecialchars($field['label']) ?><br>
            <?php if (($field['type'] ?? 'text') === 'boolean'): ?>
                    <select name="config[<?= htmlspecialchars($field['path']) ?>]">
                        <option value="1" <?= $field['value'] ? 'selected' : '' ?>>Sim</option>
                        <option value="0" <?= $field['value'] ? '' : 'selected' ?>>Não</option>
                    </select>
            <?php else: ?>
                    <input type="<?= ($field['type'] ?? 'text') === 'number' ? 'number' : 'text' ?>"
                        name="config[<?= htmlspecialchars($field['path']) ?>]"
                        value="<?= htmlspecialchars((string) $field['value']) ?>"
                        <?= isset($field['min']) ? 'min="' . (int) $field['min'] . '"' : '' ?>
                        <?= isset($field['max']) ? 'max="' . (int) $field['max'] . '"' : '' ?>
                        required>
            <?php endif; ?>
                </label>
            </p>
        <?php endforeach; ?>
        </fieldset>
    <?php endforeach; ?>
        <p><button type="submit">Salvar</button></p>
    </form>
<?php endif; ?>
</section>
