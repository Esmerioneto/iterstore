<?php
use Iter\Log\Model\OperationLog;

$e = static fn ($v) => htmlspecialchars((string) $v);
$action = OperationLog::ACTIONS[$line['action']] ?? $line['action'];
?>
<section class="log-operation-view">
    <h2><?= $e($action) ?><?= $line['entity_label'] !== null ? ': ' . $e($line['entity_label']) : '' ?></h2>
    <table class="log-operation-info">
        <tr><th>Data</th><td><?= $e(substr((string) $line['created_at'], 0, 19)) ?></td></tr>
        <tr><th>Usuário</th><td><?= $e($line['admin_name']) ?><?= $line['admin_user_id'] === null ? ' <small>(login digitado; nenhum usuário com ele)</small>' : '' ?></td></tr>
        <tr><th>Origem</th><td><?= $line['source'] === 'api' ? 'API' : 'Tela do admin' ?></td></tr>
        <tr><th>IP</th><td><?= $e($line['ip']) ?></td></tr>
        <tr><th>Ação</th><td class="log-action-<?= $e($line['action']) ?>"><?= $e($action) ?></td></tr>
<?php if ($line['entity_type'] !== null): ?>
        <tr><th>Área</th><td><?= $e(OperationLog::AREAS[$line['entity_type']] ?? $line['entity_type']) ?></td></tr>
        <tr><th>Registro</th><td><?= $link ? '<a href="' . $e($link) . '">' . $e($line['entity_label']) . '</a>' : $e($line['entity_label']) . ($line['entity_type'] === 'product' ? ' <small>(não existe mais)</small>' : '') ?></td></tr>
<?php endif; ?>
    </table>
<?php if ($line['changes']): ?>
    <h3>Campos</h3>
    <table class="log-operation-changes">
        <thead><tr><th>Campo</th><th>Antes</th><th>Depois</th></tr></thead>
        <tbody>
    <?php foreach ($line['changes'] as $field => [$before, $after]): ?>
            <tr>
                <th><?= $e($field) ?></th>
                <td><?= $before === null ? '<span class="log-empty">vazio</span>' : nl2br($e($before)) ?></td>
                <td><?= $after === null ? '<span class="log-empty">vazio</span>' : nl2br($e($after)) ?></td>
            </tr>
    <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
    <div class="log-operation-actions">
<?php if ($line['entity_type'] !== null && $line['entity_id'] !== null): ?>
        <a href="/admin/log/index?entity=<?= $e($line['entity_type'] . ':' . (int) $line['entity_id']) ?>">Outras operações deste registro</a>
<?php endif; ?>
        <a href="/admin/log/index">← Voltar para o log de operações</a>
    </div>
</section>
