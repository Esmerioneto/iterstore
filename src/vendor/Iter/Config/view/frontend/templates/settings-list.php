<section class="config-settings-list">
    <h2>Modulos do Sistema</h2>
    <table>
        <thead>
            <tr>
                <th>Grupo</th>
                <th>Vendor</th>
                <th>Modulo</th>
                <th>Status</th>
<?php if ($can_toggle): ?>
                <th>Ações</th>
<?php endif; ?>
            </tr>
        </thead>
        <tbody>
<?php foreach ($modules as $module): ?>
            <tr>
                <td><?= htmlspecialchars($module['group']) ?></td>
                <td><?= htmlspecialchars($module['vendor']) ?></td>
                <td><?= htmlspecialchars($module['name']) ?></td>
                <td><?= $module['enabled'] ? 'Ativo' : 'Inativo' ?></td>
    <?php if ($can_toggle): ?>
                <td>
        <?php if ($module['enabled'] && $module['required']): ?>
                    <small>Essencial</small>
        <?php elseif ($module['enabled'] && $module['dependents']): ?>
                    <small>Usado por <?= htmlspecialchars(implode(', ', $module['dependents'])) ?></small>
        <?php else: ?>
                    <form method="post" action="/admin/config/module" style="display:inline"
                        <?= $module['enabled'] ? 'data-confirm="Desativar o módulo ' . htmlspecialchars($module['name']) . '? As telas, menus e a API dele saem do ar; os dados continuam no banco." data-confirm-button="Desativar"' : '' ?>>
                        <?= \Iter\Core\FormKey::field() ?>
                        <input type="hidden" name="module" value="<?= htmlspecialchars($module['key']) ?>">
                        <input type="hidden" name="enabled" value="<?= $module['enabled'] ? '0' : '1' ?>">
                        <button type="submit"><?= $module['enabled'] ? 'Desativar' : 'Ativar' ?></button>
                    </form>
        <?php endif; ?>
                </td>
    <?php endif; ?>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
