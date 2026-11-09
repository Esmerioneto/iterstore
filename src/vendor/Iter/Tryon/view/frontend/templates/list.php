<?php
use Iter\Tryon\Model\TryonService;

$e = static fn ($v) => htmlspecialchars((string) $v);
$usd = static fn (float $v, int $decimals = 2) => 'US$ ' . number_format($v, $decimals, ',', '.');
$s = $summary;
$share = $s['budget'] > 0 ? $s['spent'] / $s['budget'] : 0;
?>
<section class="tryon-list">
    <div class="admin-page-head">
        <h2>Provador virtual</h2>
    </div>
<?php if ($s['refused']): ?>
    <p class="tryon-warning">A última prova (<?= $e(substr((string) $s['refused']['updated_at'], 0, 16)) ?>) foi recusada pelo provedor <?= $e($s['refused']['provider']) ?><?= $s['refused']['provider_code'] ? ' (' . $e($s['refused']['provider_code']) . ')' : '' ?>: a chave foi recusada ou o saldo acabou. Os clientes veem "O provador não está disponível agora" até isso ser resolvido em Configurações → Provador virtual.</p>
<?php endif; ?>
    <div class="report-tiles">
        <div class="report-tile<?= $share >= 1 ? ' report-tile-bad' : ($share >= 0.8 ? ' report-tile-warn' : '') ?>">
            <small>Gasto no mês</small>
            <strong><?= $e($usd($s['spent'])) ?></strong>
            <span><?= $s['budget'] > 0 ? 'de ' . $e($usd($s['budget'])) . ' do teto' : 'sem teto' ?></span>
        </div>
        <div class="report-tile">
            <small>Provas hoje</small>
            <strong><?= $s['today'] ?></strong>
            <span><?= $s['today_ok'] ?> pronta(s)</span>
        </div>
        <div class="report-tile<?= $s['week'] && $s['week_errors'] / $s['week'] >= 0.2 ? ' report-tile-warn' : '' ?>">
            <small>Erros em 7 dias</small>
            <strong><?= $s['week'] ? round(100 * $s['week_errors'] / $s['week']) . '%' : '–' ?></strong>
            <span><?= $s['week_errors'] ?> de <?= $s['week'] ?> prova(s)</span>
        </div>
        <div class="report-tile">
            <small>Provedor ativo</small>
            <strong><?= $e($s['provider'] ?? 'nenhum') ?></strong>
            <span><a href="/admin/config/index#config-group-provador-virtual">Configurações</a></span>
        </div>
    </div>
    <p class="admin-list-summary">O gasto soma o custo informado pelo provedor; uma prova que ainda está gerando entra pelo preço estimado. Uma consulta repetida (mesmo cliente, foto e peça) devolve a prova pronta sem custo e soma em "Reaproveitada". A imagem é a foto de um cliente: abrir fica no log de operações.</p>
    <form method="get" action="/admin/tryon/index" class="admin-filters">
        <select name="status">
            <option value="">Todas as situações</option>
<?php foreach (TryonService::STATUS_LABELS as $value => $label): ?>
            <option value="<?= $e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
<?php if (count($providers) > 1): ?>
        <select name="provider">
            <option value="">Todos os provedores</option>
    <?php foreach ($providers as $code): ?>
            <option value="<?= $e($code) ?>" <?= $filters['provider'] === $code ? 'selected' : '' ?>><?= $e($code) ?></option>
    <?php endforeach; ?>
        </select>
<?php endif; ?>
<?php if (count($websites) > 1): ?>
        <select name="website_id">
            <option value="">Todos os websites</option>
    <?php foreach ($websites as $value => $label): ?>
            <option value="<?= (int) $value ?>" <?= $filters['website_id'] === (string) $value ? 'selected' : '' ?>><?= $e($label) ?></option>
    <?php endforeach; ?>
        </select>
<?php endif; ?>
        <input type="search" name="q" placeholder="Cliente, produto ou SKU" value="<?= $e($filters['q']) ?>">
        <label>De <input type="date" name="from" value="<?= $e($filters['from']) ?>"></label>
        <label>até <input type="date" name="to" value="<?= $e($filters['to']) ?>"></label>
        <?= \Iter\Admin\View\Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>
    <table>
        <thead>
            <tr>
                <?= \Iter\Admin\View\Sort::th('date', 'Data', $sort, $dir) ?><?= $gridColumns->th('date') ?>
                <?= \Iter\Admin\View\Sort::th('customer', 'Cliente', $sort, $dir) ?><?= $gridColumns->th('customer') ?>
                <th>Produto</th><?= $gridColumns->th('product') ?>
                <th>Modelo</th><?= $gridColumns->th('model') ?>
                <?= \Iter\Admin\View\Sort::th('status', 'Situação', $sort, $dir) ?><?= $gridColumns->th('status') ?>
                <?= \Iter\Admin\View\Sort::th('seconds', 'Tempo', $sort, $dir) ?><?= $gridColumns->th('seconds') ?>
                <?= \Iter\Admin\View\Sort::th('cost', 'Custo', $sort, $dir) ?><?= $gridColumns->th('cost') ?>
                <?= \Iter\Admin\View\Sort::th('hits', 'Reaproveitada', $sort, $dir) ?><?= $gridColumns->th('hits') ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
<?php if (!$requests): ?>
            <tr><td colspan="<?= 9 + $gridColumns->count() ?>" class="admin-empty">Nenhuma prova encontrada.</td></tr>
<?php endif; ?>
<?php foreach ($requests as $r): $id = (int) $r['tryon_id']; $status = (string) $r['status']; ?>
            <tr>
                <td><?= $e(substr((string) $r['created_at'], 0, 16)) ?></td><?= $gridColumns->td('date', $id) ?>
                <td><a href="/admin/customer/edit?id=<?= (int) $r['customer_id'] ?>"><?= $e($r['customer_name'] ?? $r['email']) ?></a><br><small><?= $e($r['email']) ?></small></td><?= $gridColumns->td('customer', $id) ?>
                <td><?= $r['product_id'] ? '<a href="/admin/product/edit?id=' . (int) $r['edit_id'] . '">' . $e($r['product_name']) . '</a><br><small>' . $e($r['sku']) . '</small>' : '<small>Produto removido</small>' ?></td><?= $gridColumns->td('product', $id) ?>
                <td><?= $e($r['provider']) ?><br><small><?= $e($r['model']) ?></small></td><?= $gridColumns->td('model', $id) ?>
                <td class="tryon-status-<?= $e($status) ?>"<?= $r['error_code'] ? ' title="' . $e(TryonService::message($r['error_code'])) . '"' : '' ?>><?= $e(TryonService::STATUS_LABELS[$status] ?? $status) ?><?= $r['error_code'] ? '<br><small>' . $e($r['error_code']) . ($r['provider_code'] ? ' · ' . $e($r['provider_code']) : '') . '</small>' : '' ?></td><?= $gridColumns->td('status', $id) ?>
                <td><?= $r['seconds'] !== null ? $e(number_format((float) $r['seconds'], 1, ',', '.') . ' s') : '' ?></td><?= $gridColumns->td('seconds', $id) ?>
                <td><?= $r['cost'] !== null ? $e($usd((float) $r['cost'], 4)) : '' ?></td><?= $gridColumns->td('cost', $id) ?>
                <td><?= (int) $r['hits'] ?: '' ?></td><?= $gridColumns->td('hits', $id) ?>
                <td class="admin-row-action"><?= TryonService::imageOpen($r) ? '<a href="/admin/tryon/image?id=' . $id . '" target="_blank" rel="noopener noreferrer">Ver imagem</a>' : '' ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
