<?php
use Iter\Log\Model\OperationLog;

$e = static fn ($v) => htmlspecialchars((string) $v);
?>
<section class="log-operation-list">
    <div class="admin-page-head">
        <h2>Log de operações</h2>
    </div>
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
        <?= \Iter\Admin\View\Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
<?php if ($filters['entity'] !== ''): ?>
    <p class="admin-list-summary">Só as operações de um registro<?= str_starts_with($filters['entity'], 'product:') ? ' (o produto e as variações dele)' : '' ?>. <a href="/admin/log/index">Ver todas as operações</a></p>
<?php endif; ?>
    <table>
        <thead>
            <tr>
                <?= \Iter\Admin\View\Sort::th('date', 'Data', $sort, $dir) ?><?= $gridColumns->th('date') ?>
                <?= \Iter\Admin\View\Sort::th('user', 'Usuário', $sort, $dir) ?><?= $gridColumns->th('user') ?>
                <?= \Iter\Admin\View\Sort::th('source', 'Origem', $sort, $dir) ?><?= $gridColumns->th('source') ?>
                <?= \Iter\Admin\View\Sort::th('action', 'Ação', $sort, $dir) ?><?= $gridColumns->th('action') ?>
                <?= \Iter\Admin\View\Sort::th('area', 'Área', $sort, $dir) ?><?= $gridColumns->th('area') ?>
                <?= \Iter\Admin\View\Sort::th('record', 'Registro', $sort, $dir) ?><?= $gridColumns->th('record') ?>
                <th>Resumo</th><?= $gridColumns->th('summary') ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (!$lines): ?>
            <tr><td colspan="<?= 8 + $gridColumns->count() ?>" class="admin-empty">Nenhuma operação encontrada.</td></tr>
<?php endif; ?>
<?php foreach ($lines as $line): ?>
            <tr>
                <td><?= $e(substr((string) $line['created_at'], 0, 16)) ?></td><?= $gridColumns->td('date', $line['log_id']) ?>
                <td><?= $e($line['admin_name']) ?></td><?= $gridColumns->td('user', $line['log_id']) ?>
                <td><?= $line['source'] === 'api' ? 'API' : 'Tela' ?></td><?= $gridColumns->td('source', $line['log_id']) ?>
                <td class="log-action-<?= $e($line['action']) ?>"><?= $e(OperationLog::ACTIONS[$line['action']] ?? $line['action']) ?></td><?= $gridColumns->td('action', $line['log_id']) ?>
                <td><?= $e(OperationLog::AREAS[$line['entity_type'] ?? ''] ?? '') ?></td><?= $gridColumns->td('area', $line['log_id']) ?>
                <td><?= $e($line['entity_label']) ?></td><?= $gridColumns->td('record', $line['log_id']) ?>
                <td><?= $e(OperationLog::summary($line)) ?></td><?= $gridColumns->td('summary', $line['log_id']) ?>
                <td class="admin-row-action"><a href="/admin/log/view?id=<?= (int) $line['log_id'] ?>">Ver</a></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
