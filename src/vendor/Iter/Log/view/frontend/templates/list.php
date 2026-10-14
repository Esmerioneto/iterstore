<?php
use Iter\Log\Model\OperationLog;

$e = static fn ($v) => htmlspecialchars((string) $v);
?>
<section class="log-operation-list">
    <h2>Log de operações</h2>
    <form method="get" action="/admin/log/index" class="admin-filters">
<?php if ($filters['entity'] !== ''): // "Ver todas" of a record's history: kept until "Limpar" ?>
        <input type="hidden" name="entity" value="<?= $e($filters['entity']) ?>">
<?php endif; ?>
        <label>De <input type="date" name="from" value="<?= $e($filters['from']) ?>"></label>
        <label>até <input type="date" name="to" value="<?= $e($filters['to']) ?>"></label>
        <select name="user">
            <option value="">Todos os usuários</option>
<?php foreach ($users as $user): ?>
            <option value="<?= $e($user) ?>" <?= $filters['user'] === $user ? 'selected' : '' ?>><?= $e($user) ?></option>
<?php endforeach; ?>
        </select>
        <select name="action">
            <option value="">Todas as ações</option>
<?php foreach (OperationLog::ACTIONS as $value => $label): ?>
            <option value="<?= $e($value) ?>" <?= $filters['action'] === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
        <select name="area">
            <option value="">Todas as áreas</option>
<?php foreach (OperationLog::AREAS as $value => $label): ?>
            <option value="<?= $e($value) ?>" <?= $filters['area'] === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
        <input type="search" name="q" placeholder="Registro (SKU, nome, e-mail…)" value="<?= $e($filters['q']) ?>">
        <button type="submit">Filtrar</button>
    </form>
<?php if ($filters['entity'] !== ''): ?>
    <p class="admin-list-summary">Só as operações de um registro<?= str_starts_with($filters['entity'], 'product:') ? ' (o produto e as variações dele)' : '' ?>. <a href="/admin/log/index">Ver todas as operações</a></p>
<?php endif; ?>
    <table>
        <thead>
            <tr>
                <th>Data</th>
                <th>Usuário</th>
                <th>Origem</th>
                <th>Ação</th>
                <th>Área</th>
                <th>Registro</th>
                <th>Resumo</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (!$lines): ?>
            <tr><td colspan="8">Nenhuma operação encontrada.</td></tr>
<?php endif; ?>
<?php foreach ($lines as $line): ?>
            <tr>
                <td><?= $e(substr((string) $line['created_at'], 0, 16)) ?></td>
                <td><?= $e($line['admin_name']) ?></td>
                <td><?= $line['source'] === 'api' ? 'API' : 'Tela' ?></td>
                <td class="log-action-<?= $e($line['action']) ?>"><?= $e(OperationLog::ACTIONS[$line['action']] ?? $line['action']) ?></td>
                <td><?= $e(OperationLog::AREAS[$line['entity_type'] ?? ''] ?? '') ?></td>
                <td><?= $e($line['entity_label']) ?></td>
                <td><?= $e(OperationLog::summary($line)) ?></td>
                <td class="admin-row-action"><a href="/admin/log/view?id=<?= (int) $line['log_id'] ?>">Ver</a></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
