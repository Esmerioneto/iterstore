<?php
/** @var array|null $schema given directly, or built from entity_type / attribute_set / fields */
$schema ??= (new \Iter\Eav\Form\FormSchemaGenerator())->generate($entity_type, $attribute_set, $fields);
$html = ['number' => 'number', 'integer' => 'number', 'date' => 'date', 'datetime' => 'datetime-local', 'hidden' => 'hidden'];
$e = static fn ($v) => htmlspecialchars((string) $v);
// optional: fields placed outside their <form> (product form layout) point to it with form="..."
$formAttr = ($form_id ?? '') === '' ? '' : ' form="' . $e($form_id) . '"';
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
    <div class="eav-field<?= isset($f['accept']) ? ' eav-field-file' : '' ?>" data-field="<?= $e($f['name']) ?>">
        <?php if (isset($f['accept'])): // file field: the value is the stored file's path
            $file = is_string($value) ? $value : '';
        ?>
    <p><label><?= $label ?><br>
            <?php if ($file !== ''): ?>
        <a href="<?= $e(\Iter\Eav\Model\ValueService::MEDIA_URL . $file) ?>" target="_blank" data-image-popup="<?= $e($f['label']) ?>"><img class="eav-file-preview" src="<?= $e(\Iter\Eav\Model\ValueService::MEDIA_URL . $file) ?>" alt=""></a><br>
            <?php endif; ?>
        <input<?= $formAttr ?> type="file" name="<?= $name ?>" accept="<?= $e(implode(',', $f['accept'])) ?>" <?= $file === '' ? $required : '' ?>>
    </label>
            <?php if ($file !== '' && !$f['required']): ?>
        <label><input<?= $formAttr ?> type="checkbox" name="<?= $name ?>[remove]" value="1"> Remover</label>
            <?php endif; ?>
    </p>
        <?php elseif ($f['type'] === 'hidden'): ?>
    <input<?= $formAttr ?> type="hidden" name="<?= $name ?>" value="<?= $e($value) ?>">
        <?php elseif ($f['type'] === 'checkbox'): ?>
    <p><label><input<?= $formAttr ?> type="hidden" name="<?= $name ?>" value="0"><input<?= $formAttr ?> type="checkbox" name="<?= $name ?>" value="1" <?= $value ? 'checked' : '' ?>> <?= $label ?></label></p>
        <?php elseif (isset($f['options']) && $f['type'] === 'radio'): ?>
    <fieldset><legend><?= $label ?></legend>
            <?php foreach ($f['options'] as $o): ?>
        <label><input<?= $formAttr ?> type="radio" name="<?= $name ?>" value="<?= $e($o['value']) ?>" <?= in_array($o['value'], $selected, true) ? 'checked' : '' ?> <?= $required ?>> <?= $e($o['label']) ?></label>
            <?php endforeach; ?>
    </fieldset>
        <?php elseif (isset($f['options'])): ?>
    <p><label><?= $label ?><br>
        <select<?= $formAttr ?> name="<?= $name ?>" <?= $f['multiple'] ? 'multiple' : '' ?> <?= $required ?>>
            <?php if (!$f['multiple']): ?><option value="">Selecione</option><?php endif; ?>
            <?php foreach ($f['options'] as $o): ?>
            <option value="<?= $e($o['value']) ?>" <?= in_array($o['value'], $selected, true) ? 'selected' : '' ?>><?= $e($o['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </label></p>
        <?php elseif ($f['type'] === 'textarea'): ?>
    <p><label><?= $label ?><br><textarea<?= $formAttr ?> name="<?= $name ?>" <?= $required ?>><?= $e($value) ?></textarea></label></p>
        <?php else: ?>
    <p><label><?= $label ?><br><input<?= $formAttr ?> type="<?= $html[$f['type']] ?? 'text' ?>" name="<?= $name ?>" value="<?= $e($value) ?>" <?= $f['type'] === 'number' ? 'step="any"' : '' ?> <?= $required ?>></label></p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</fieldset>
<?php endforeach; ?>
