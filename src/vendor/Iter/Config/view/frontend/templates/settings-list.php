<section class="config-settings-list" id="modulos">
    <div class="admin-page-head">
        <h2>Modulos do Sistema</h2>
    </div>
    <form method="get" action="/admin/config/index#modulos" class="admin-filters">
<?php if (ctype_digit((string) ($_GET['website'] ?? ''))): ?>
        <input type="hidden" name="website" value="<?= (int) $_GET['website'] ?>">
<?php endif; ?>
        <input type="search" name="q" placeholder="Grupo, vendor ou módulo" value="<?= htmlspecialchars($q) ?>">
        <?= \Iter\Admin\View\Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
                <?= \Iter\Admin\View\Sort::th('group', 'Grupo', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('vendor', 'Vendor', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('name', 'Modulo', $sort, $dir) ?>
                <?= \Iter\Admin\View\Sort::th('enabled', 'Status', $sort, $dir) ?>
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
                <td><span class="admin-status admin-status-<?= $module['enabled'] ? 'on' : 'off' ?>"><?= $module['enabled'] ? 'Ativo' : 'Inativo' ?></span></td>
    <?php if ($can_toggle): ?>
                <td class="admin-row-action">
        <?php if ($module['enabled'] && $module['required']): ?>
                    <small>Essencial</small>
        <?php elseif ($module['enabled'] && $module['dependents']): ?>
                    <small>Usado por <?= htmlspecialchars(implode(', ', $module['dependents'])) ?></small>
        <?php else: ?>
                    <form method="post" action="/admin/config/module"
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
