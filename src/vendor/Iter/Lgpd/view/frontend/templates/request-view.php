<?php
use Iter\Lgpd\Model\RequestService;

$e = static fn ($v) => htmlspecialchars((string) $v);
$r = $request;
$customerId = $r['customer_id'] !== null ? (int) $r['customer_id'] : null;
?>
<section class="mail-view lgpd-request-view">
    <h2>Solicitação <?= $e($protocol) ?></h2>
    <table class="mail-message-info">
        <tr><th>Situação</th><td><span class="admin-status admin-status-<?= $r['status'] === 'done' ? 'on' : ($r['status'] === 'open' ? 'pending_payment' : 'off') ?>"><?= $e(RequestService::STATUS_LABELS[$r['status']] ?? $r['status']) ?></span><?= $r['done_at'] ? ' em ' . $e(date('d/m/Y H:i', strtotime((string) $r['done_at']))) . ($r['admin_name'] ? ' por ' . $e($r['admin_name']) : '') : '' ?></td></tr>
        <tr><th>Titular</th><td><?= $e($r['email']) ?> —
<?php if ($customerId === null): ?>
            visitante (sem cadastro neste e-mail)
<?php elseif ($r['anonymized_at']): ?>
            cliente excluído em <?= $e(date('d/m/Y H:i', strtotime((string) $r['anonymized_at']))) ?>
<?php else: ?>
            <a href="/admin/customer/edit?id=<?= $customerId ?>">Cliente #<?= $customerId ?></a>
<?php endif; ?>
        </td></tr>
        <tr><th>Pedido</th><td><?= $e(RequestService::TYPES[$r['type']] ?? $r['type']) ?></td></tr>
        <tr><th>Origem</th><td><?= $e(RequestService::ORIGINS[$r['origin']] ?? $r['origin']) ?><?= $r['website'] ? ' — ' . $e($r['website']) : '' ?></td></tr>
        <tr><th>Recebida em</th><td><?= $e(date('d/m/Y H:i', strtotime((string) $r['created_at']))) ?></td></tr>
<?php if ($r['status'] === 'open'): ?>
        <tr><th>Responder até</th><td><?= $late ? '<span class="admin-status admin-status-canceled">Atrasada</span> ' : '' ?><?= $e(date('d/m/Y', strtotime((string) $r['due_at']))) ?></td></tr>
<?php endif; ?>
<?php if ($r['note']): ?>
        <tr><th>Observações</th><td><?= nl2br($e($r['note'])) ?></td></tr>
<?php endif; ?>
    </table>

<?php if (!$r['anonymized_at'] || $customerId === null): ?>
    <h3>Cópia dos dados</h3>
    <p>Tudo o que a loja guarda sobre o titular, por assunto, num arquivo JSON para enviar a ele. <?= $customerId === null ? 'Visitante: entram os pedidos, a newsletter e os e-mails com este endereço.' : '' ?> Cada download fica no log de operações.</p>
    <div class="mail-message-actions"><a class="admin-button" href="/admin/lgpdrequest/download?id=<?= (int) $r['request_id'] ?>">Baixar a cópia (JSON)</a></div>
<?php endif; ?>

<?php if ($canErase || $eraseNeedsPermission): ?>
    <h3>Excluir os dados</h3>
    <?php if ($blockers): ?>
    <p class="lgpd-alert"><?= $e(implode(' ', $blockers)) ?></p>
    <?php elseif ($eraseNeedsPermission): ?>
    <p class="lgpd-alert">Seu perfil não pode excluir dados de clientes (permissão "LGPD: excluir os dados de um cliente").</p>
    <?php else: ?>
    <p>Apaga cadastro, endereços, favoritos, newsletter, mensagens e provador, e o cliente não entra mais. Os pedidos ficam pelo prazo fiscal. Um e-mail confirma a exclusão ao titular. <strong>Não tem volta.</strong></p>
    <form method="post" action="/admin/lgpdrequest/erase" data-confirm="Excluir os dados deste cliente? Não tem volta." class="lgpd-action">
        <?= \Iter\Core\FormKey::field() ?>
        <input type="hidden" name="id" value="<?= (int) $r['request_id'] ?>">
        <input type="text" name="note" placeholder="Observação (opcional)">
        <button type="submit">Excluir os dados</button>
    </form>
    <?php endif; ?>
<?php endif; ?>

<?php if ($r['status'] === 'open'): ?>
    <h3>Encerrar</h3>
    <p><?= $r['type'] === 'erase' && $customerId === null ? 'Visitante sem cadastro: os pedidos ficam pelo prazo fiscal e são anonimizados depois. Diga isso ao titular e conclua com essa justificativa.' : 'Depois de responder ao titular (ex.: enviar a cópia, corrigir o cadastro), conclua. Recusar pede o motivo.' ?></p>
    <form method="post" action="/admin/lgpdrequest/close" class="lgpd-action">
        <?= \Iter\Core\FormKey::field() ?>
        <input type="hidden" name="id" value="<?= (int) $r['request_id'] ?>">
        <input type="text" name="note" placeholder="O que foi feito, ou o motivo da recusa">
        <button type="submit" name="status" value="done">Concluir</button>
        <button type="submit" name="status" value="refused">Recusar</button>
    </form>
<?php endif; ?>
    <div class="mail-message-actions"><a href="/admin/lgpdrequest/index">← Voltar para as solicitações</a></div>
</section>
