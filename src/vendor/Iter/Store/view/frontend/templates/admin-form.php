<section class="store-admin-form">
    <h2><?= $is_edit ? 'Editar loja' : 'Nova loja' ?></h2>
    <form method="post" action="/admin/store/save">
        <?= \Iter\Core\FormKey::field() ?>
<?php if ($id): ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">
<?php endif; ?>
        <p>
            <label>Nome<br>
                <input type="text" name="store_name" maxlength="255" value="<?= htmlspecialchars((string) $values['store_name']) ?>" required>
            </label>
        </p>
        <p>
            <label>Webview<br>
                <input type="text" name="webview" maxlength="255" value="<?= htmlspecialchars((string) $values['webview']) ?>" required>
            </label>
        </p>
        <p>
            <label>Tema<br>
                <input type="text" name="theme" maxlength="255" value="<?= htmlspecialchars((string) $values['theme']) ?>" required>
            </label>
        </p>
        <p>
            <label>Grupo de loja<br>
                <select name="group_id">
                    <option value="">Nenhum</option>
<?php foreach ($groups as $group): ?>
                    <option value="<?= (int) $group['group_id'] ?>" <?= (string) $values['group_id'] === (string) $group['group_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($group['website_name'] . ' / ' . $group['name']) ?>
                    </option>
<?php endforeach; ?>
                </select>
            </label>
        </p>
        <p>
            <button type="submit">Salvar</button>
            <a href="/admin/store/index">Cancelar</a>
        </p>
    </form>
</section>
