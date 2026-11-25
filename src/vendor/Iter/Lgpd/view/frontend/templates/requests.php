<?php
use Iter\Admin\View\Sort;
use Iter\Lgpd\Model\RequestService;

$e = static fn ($v) => htmlspecialchars((string) $v);
?>
<section class="lgpd-requests">
    <div class="admin-page-head">
        <div>
            <h2>Solicitações LGPD</h2>
            <p class="admin-list-summary">Pedidos dos titulares (art. 18): saber o que a loja tem, receber a cópia, corrigir ou excluir. Os feitos pelo app e pela loja web já entram atendidos; os que chegam por e-mail ou telefone, registre aqui. Prazo de resposta: <?= RequestService::DUE_DAYS ?> dias.</p>
        </div>
        <a class="admin-button admin-button-primary" href="/admin/lgpdrequest/new">+ Nova solicitação</a>
    </div>
    <form method="get" action="/admin/lgpdrequest/index" class="admin-filters">
        <select name="status">
            <option value="">Todas as situações</option>
<?php foreach (RequestService::STATUS_LABELS as $value => $label): ?>
            <option value="<?= $e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
        <select name="type">
            <option value="">Todos os tipos</option>
<?php foreach (RequestService::TYPES as $value => $label): ?>
            <option value="<?= $e($value) ?>" <?= $filters['type'] === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
<?php if (count($websites) > 1): ?>
        <select name="website_id">
            <option value="">Todos os websites</option>
    <?php foreach ($websites as $value => $label): ?>
            <option value="<?= (int) $value ?>" <?= $filters['website_id'] === (string) $value ? 'selected' : '' ?>><?= $e($label) ?></option>
    <?php endforeach; ?>
        </select>
<?php endif; ?>
        <input type="search" name="q" placeholder="E-mail ou número" value="<?= $e($filters['q']) ?>">
        <label class="mobile-switch"><input type="hidden" name="late" value=""><input type="checkbox" class="admin-switch" name="late" value="1" <?= $filters['late'] ? 'checked' : '' ?>> Só atrasadas</label>
        <?= Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
                <?= Sort::th('id', 'Protocolo', $sort, $dir) ?><?= $gridColumns->th('id') ?>
                <?= Sort::th('email', 'Titular', $sort, $dir) ?><?= $gridColumns->th('email') ?>
                <?= Sort::th('type', 'Tipo', $sort, $dir) ?><?= $gridColumns->th('type') ?>
                <?= Sort::th('origin', 'Origem', $sort, $dir) ?><?= $gridColumns->th('origin') ?>
                <?= Sort::th('date', 'Recebida em', $sort, $dir) ?><?= $gridColumns->th('date') ?>
                <?= Sort::th('due', 'Prazo', $sort, $dir) ?><?= $gridColumns->th('due') ?>
                <?= Sort::th('status', 'Situação', $sort, $dir) ?><?= $gridColumns->th('status') ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (!$requests): ?>
            <tr><td colspan="<?= 8 + $gridColumns->count() ?>" class="admin-empty">Nenhuma solicitação encontrada.</td></tr>
<?php endif; ?>
<?php foreach ($requests as $r): $id = (int) $r['request_id']; $late = $r['status'] === 'open' && strtotime((string) $r['due_at']) < time(); ?>
            <tr>
                <td><code><?= $e(RequestService::protocol($id)) ?></code></td><?= $gridColumns->td('id', $id) ?>
                <td><?= $e($r['email']) ?><br><small><?= $r['customer_id'] ? ($r['anonymized_at'] ? 'Cliente excluído' : 'Cliente #' . (int) $r['customer_id']) : 'Visitante' ?></small></td><?= $gridColumns->td('email', $id) ?>
                <td><?= $e(RequestService::TYPES[$r['type']] ?? $r['type']) ?></td><?= $gridColumns->td('type', $id) ?>
                <td><?= $e(RequestService::ORIGINS[$r['origin']] ?? $r['origin']) ?></td><?= $gridColumns->td('origin', $id) ?>
                <td><?= $e(date('d/m/Y H:i', strtotime((string) $r['created_at']))) ?></td><?= $gridColumns->td('date', $id) ?>
                <td><?= $r['status'] === 'open' ? ($late ? '<span class="admin-status admin-status-canceled">Atrasada: ' . $e(date('d/m/Y', strtotime((string) $r['due_at']))) . '</span>' : $e(date('d/m/Y', strtotime((string) $r['due_at'])))) : '<small>—</small>' ?></td><?= $gridColumns->td('due', $id) ?>
                <td><span class="admin-status admin-status-<?= $r['status'] === 'done' ? 'on' : ($r['status'] === 'open' ? 'pending_payment' : 'off') ?>"><?= $e(RequestService::STATUS_LABELS[$r['status']] ?? $r['status']) ?></span></td><?= $gridColumns->td('status', $id) ?>
                <td class="admin-row-action"><a class="admin-button" href="/admin/lgpdrequest/view?id=<?= $id ?>">Abrir</a></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
