<?php
use Iter\Log\Model\OperationLog;

if ($lines !== null):
$e = static fn ($v) => htmlspecialchars((string) $v);
?>
<section class="log-history">
    <h2>Histórico de alterações</h2>
    <?php if (!$lines): ?>
    <p>Nenhuma alteração registrada ainda.</p>
    <?php else: ?>
    <table>
        <thead><tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Resumo</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($lines as $line): ?>
            <tr>
                <td><?= $e(substr((string) $line['created_at'], 0, 16)) ?></td>
                <td><?= $e($line['admin_name']) ?></td>
                <td class="log-action-<?= $e($line['action']) ?>"><?= $e(OperationLog::ACTIONS[$line['action']] ?? $line['action']) ?><?= (int) $line['entity_id'] !== $own_id ? ' <small>' . $e($line['entity_label']) . '</small>' : '' ?></td>
                <td><?= $e(OperationLog::summary($line)) ?></td>
                <td class="admin-row-action"><a href="/admin/log/view?id=<?= (int) $line['log_id'] ?>">Ver</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
        <?php if ($total > count($lines)): ?>
    <p class="admin-list-summary">As <?= count($lines) ?> mais recentes de <?= (int) $total ?>.</p>
        <?php endif; ?>
    <?php endif; ?>
    <p><a href="/admin/log/index?entity=<?= $e($entity) ?>">Ver todas no log de operações</a></p>
</section>
<?php endif; ?>
