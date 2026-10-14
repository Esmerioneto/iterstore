<?php if ($summary === null) return; ?>
<section class="admin-dashboard-card">
    <h2>Usuários</h2>
    <p>
        <a href="/admin/user/index"><?= $summary['active'] ?> ativo(s)</a>
        · <?= $summary['inactive'] ?> inativo(s)
        · <?= count($summary['locked']) ?> bloqueado(s)
    </p>
<?php if ($summary['locked']): ?>
    <h3>Bloqueados agora</h3>
    <table>
        <thead><tr><th>Usuário</th><th>Liberado em</th></tr></thead>
        <tbody>
    <?php foreach ($summary['locked'] as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['username']) ?></td>
                <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($row['lock_expires']))) ?></td>
            </tr>
    <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
    <h3>Últimos logins</h3>
    <table>
        <thead><tr><th>Usuário</th><th>Data</th><th>Total de logins</th></tr></thead>
        <tbody>
<?php if (empty($summary['recent'])): ?>
            <tr><td colspan="3" class="admin-empty">Nenhum login registrado.</td></tr>
<?php else: ?>
    <?php foreach ($summary['recent'] as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['username']) ?></td>
                <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($row['logdate']))) ?></td>
                <td><?= (int) $row['lognum'] ?></td>
            </tr>
    <?php endforeach; ?>
<?php endif; ?>
        </tbody>
    </table>
</section>
