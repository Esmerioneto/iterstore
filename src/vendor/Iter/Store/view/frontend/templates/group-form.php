<section class="store-group-form">
    <h2><?= $is_edit ? 'Editar grupo' : 'Novo grupo' ?></h2>
<?php if (empty($websites)): ?>
    <p>Cadastre um <a href="/admin/website/create">website</a> antes de criar grupos.</p>
<?php else: ?>
    <form method="post" action="/admin/group/save">
        <?= \Iter\Core\FormKey::field() ?>
<?php if ($group_id): ?>
        <input type="hidden" name="group_id" value="<?= (int) $group_id ?>">
<?php endif; ?>
        <p>
            <label>Website<br>
                <select name="website_id" required>
                    <option value="">Selecione</option>
<?php foreach ($websites as $website): ?>
                    <option value="<?= (int) $website['website_id'] ?>" <?= (string) $values['website_id'] === (string) $website['website_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $website['name']) ?>
                    </option>
<?php endforeach; ?>
                </select>
            </label>
        </p>
        <p>
            <label>Código<br>
                <input type="text" name="code" maxlength="32" pattern="[a-z][a-z0-9_]*" value="<?= htmlspecialchars((string) $values['code']) ?>" required>
            </label>
        </p>
        <p>
            <label>Nome<br>
                <input type="text" name="name" maxlength="255" value="<?= htmlspecialchars((string) $values['name']) ?>" required>
            </label>
        </p>
        <p>
            <button type="submit">Salvar</button>
            <a href="/admin/group/index">Cancelar</a>
        </p>
    </form>
<?php endif; ?>
</section>
