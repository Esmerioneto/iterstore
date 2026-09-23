<section class="config-settings-list">
    <h2>Modulos do Sistema</h2>
    <table>
        <thead>
            <tr>
                <th>Grupo</th>
                <th>Vendor</th>
                <th>Modulo</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
<?php foreach ($modules as $module): ?>
            <tr>
                <td><?= htmlspecialchars($module['group']) ?></td>
                <td><?= htmlspecialchars($module['vendor']) ?></td>
                <td><?= htmlspecialchars($module['name']) ?></td>
                <td><?= $module['enabled'] ? 'Ativo' : 'Inativo' ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
</section>
