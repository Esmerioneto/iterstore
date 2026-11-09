<?php
use Iter\Marketing\Model\MarketingService;

$e = static fn ($v) => htmlspecialchars((string) $v);
$s = $suggestion;
$status = (string) $s['status'];
?>
<section class="mail-view marketing-view">
    <h2><?= $e($preview['subject'] ?: 'Sugestão de e-mail') ?></h2>
    <table class="mail-message-info">
        <tr><th>Situação</th><td class="marketing-status-<?= $e($status) ?>"><?= $e(MarketingService::STATUS_LABELS[$status] ?? $status) ?><?= $s['note'] ? ' — ' . $e($s['note']) : '' ?></td></tr>
        <tr><th>Cliente</th><td><?php if ($s['customer_id']): ?><a href="/admin/customer/edit?id=<?= (int) $s['customer_id'] ?>"><?= $e($s['customer_name'] ?? $s['email']) ?></a><?php else: ?>Visitante<?php endif; ?> <small><?= $e($s['email']) ?></small></td></tr>
        <tr><th>Regra</th><td><?= $e($rule) ?></td></tr>
        <tr><th>Motivo</th><td><?= $e($reason) ?></td></tr>
        <tr><th>Website</th><td><?= $e($s['website']) ?></td></tr>
        <tr><th>Criada em</th><td><?= $e(substr((string) $s['created_at'], 0, 16)) ?></td></tr>
<?php if ($s['sent_at']): ?>
        <tr><th>Enviada em</th><td><?= $e(substr((string) $s['sent_at'], 0, 16)) ?><?= $s['mail_id'] ? ' — <a href="/admin/mail/view?id=' . (int) $s['mail_id'] . '">ver o e-mail enviado</a>' : '' ?></td></tr>
<?php endif; ?>
    </table>
<?php if ($status === 'sent'): ?>
    <p class="mail-message-note">Prévia com o texto de hoje do modelo. O e-mail que saiu está em Sistema → E-mails enviados.</p>
<?php endif; ?>
    <h3>Prévia</h3>
    <iframe class="mail-preview" sandbox srcdoc="<?= $e($preview['html']) ?>"></iframe>
    <div class="mail-message-actions">
<?php if ($status === 'pending'): ?>
        <form method="post" action="/admin/marketing/decide">
            <?= \Iter\Core\FormKey::field() ?>
            <input type="hidden" name="ids[]" value="<?= (int) $s['suggestion_id'] ?>">
            <button type="submit" name="action" value="send">Enviar</button>
            <button type="submit" name="action" value="discard">Descartar</button>
        </form>
<?php endif; ?>
        <a href="/admin/marketing/index">← Voltar para as sugestões</a>
    </div>
</section>
