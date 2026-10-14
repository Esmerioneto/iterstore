<section class="admin-role-form">
    <h2><?= $is_edit ? 'Editar perfil' : 'Novo perfil' ?></h2>
    <form method="post" action="/admin/role/save">
        <?= \Iter\Core\FormKey::field() ?>
<?php if ($role_id): ?>
        <input type="hidden" name="role_id" value="<?= (int) $role_id ?>">
<?php endif; ?>
        <p>
            <label>Nome do perfil<br>
                <input type="text" name="role_name" value="<?= htmlspecialchars($role_name) ?>" required>
            </label>
        </p>
        <fieldset class="admin-role-permissions">
            <legend>Permissões</legend>
<?php foreach ($nodes as $node): ?>
            <label class="admin-role-permission" style="padding-left: <?= $node['depth'] * 20 ?>px">
                <input type="checkbox" name="resources[]" value="<?= htmlspecialchars($node['id']) ?>" <?= in_array($node['id'], $granted, true) ? 'checked' : '' ?>>
                <?= htmlspecialchars($node['title']) ?>
            </label>
<?php endforeach; ?>
        </fieldset>
        <p>
            <button type="submit" class="admin-button-primary">Salvar</button>
            <a href="/admin/role/index">Cancelar</a>
        </p>
    </form>
</section>
