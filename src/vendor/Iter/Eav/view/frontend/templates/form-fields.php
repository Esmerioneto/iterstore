<?php
/** @var array|null $schema given directly, or built from entity_type / attribute_set / fields */
$schema ??= (new \Iter\Eav\Form\FormSchemaGenerator())->generate($entity_type, $attribute_set, $fields);
$html = ['number' => 'number', 'integer' => 'number', 'date' => 'date', 'datetime' => 'datetime-local', 'hidden' => 'hidden'];
$e = static fn ($v) => htmlspecialchars((string) $v);
$groups = [];
foreach ($schema['fields'] as $field) {
    $groups[$field['group'] ?? ''][] = $field;
}
?>
<?php foreach ($groups as $group => $groupFields): ?>
<fieldset class="eav-form-fields">
    <?php if ($group !== ''): ?><legend><?= $e($group) ?></legend><?php endif; ?>
    <?php foreach ($groupFields as $f):
        $value = $values[$f['name']] ?? $f['default'];
        if (is_string($value) && isset($value[9])) { // stored "Y-m-d H:i:s" -> what date inputs accept
            $value = ['date' => substr($value, 0, 10), 'datetime' => str_replace(' ', 'T', substr($value, 0, 16))][$f['type']] ?? $value;
        }
        $selected = array_map('strval', (array) $value);
        $name = $e(($name_prefix ?? '') === '' ? $f['name'] : "{$name_prefix}[{$f['name']}]") . ($f['multiple'] ? '[]' : '');
        $required = $f['required'] ? 'required' : '';
        $label = $e($f['label']) . ($f['required'] ? ' *' : '');
    ?>
        <?php if ($f['type'] === 'hidden'): ?>
    <input type="hidden" name="<?= $name ?>" value="<?= $e($value) ?>">
        <?php elseif ($f['type'] === 'checkbox'): ?>
    <p><label><input type="hidden" name="<?= $name ?>" value="0"><input type="checkbox" name="<?= $name ?>" value="1" <?= $value ? 'checked' : '' ?>> <?= $label ?></label></p>
        <?php elseif (isset($f['options']) && $f['type'] === 'radio'): ?>
    <fieldset><legend><?= $label ?></legend>
            <?php foreach ($f['options'] as $o): ?>
        <label><input type="radio" name="<?= $name ?>" value="<?= $e($o['value']) ?>" <?= in_array($o['value'], $selected, true) ? 'checked' : '' ?> <?= $required ?>> <?= $e($o['label']) ?></label>
            <?php endforeach; ?>
    </fieldset>
        <?php elseif (isset($f['options'])): ?>
    <p><label><?= $label ?><br>
        <select name="<?= $name ?>" <?= $f['multiple'] ? 'multiple' : '' ?> <?= $required ?>>
            <?php if (!$f['multiple']): ?><option value="">Selecione</option><?php endif; ?>
            <?php foreach ($f['options'] as $o): ?>
            <option value="<?= $e($o['value']) ?>" <?= in_array($o['value'], $selected, true) ? 'selected' : '' ?>><?= $e($o['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </label></p>
        <?php elseif ($f['type'] === 'textarea'): ?>
    <p><label><?= $label ?><br><textarea name="<?= $name ?>" <?= $required ?>><?= $e($value) ?></textarea></label></p>
        <?php else: ?>
    <p><label><?= $label ?><br><input type="<?= $html[$f['type']] ?? 'text' ?>" name="<?= $name ?>" value="<?= $e($value) ?>" <?= $f['type'] === 'number' ? 'step="any"' : '' ?> <?= $required ?>></label></p>
        <?php endif; ?>
    <?php endforeach; ?>
</fieldset>
<?php endforeach; ?>
