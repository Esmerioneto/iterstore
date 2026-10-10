<?php
use Iter\Core\Config;
use Iter\Mail\Model\MailService;

$e = static fn ($v) => htmlspecialchars((string) $v);
$status = (int) $message['status'];
?>
<section class="mail-view mail-message-view">
    <h2><?= $e($message['subject']) ?></h2>
    <table class="mail-message-info">
        <tr><th>Situação</th><td class="mail-status-<?= $status ?>"><?= $e(MailService::STATUS_LABELS[$status]) ?> (<?= (int) $message['attempts'] ?> tentativa(s))<?= $status === MailService::PENDING ? ' — próxima em ' . $e(substr((string) $message['send_after'], 0, 16)) : '' ?></td></tr>
        <tr><th>Criado em</th><td><?= $e(substr((string) $message['created_at'], 0, 19)) ?></td></tr>
        <tr><th>Enviado em</th><td><?= $e(substr((string) $message['sent_at'], 0, 19)) ?></td></tr>
        <tr><th>De</th><td><?= $e(trim($message['from_name'] . ' <' . $message['from_email'] . '>')) ?></td></tr>
<?php foreach (['to' => 'Para', 'cc' => 'Cc', 'bcc' => 'Cco', 'reply_to' => 'Responder para'] as $key => $label): ?>
    <?php if ($recipients[$key]): ?>
        <tr><th><?= $label ?></th><td><?= $e(implode(', ', $recipients[$key])) ?></td></tr>
    <?php endif; ?>
<?php endforeach; ?>
        <tr><th>Modelo</th><td><code><?= $e($message['template_code']) ?></code></td></tr>
<?php if ($message['attachments'] !== '[]'): ?>
        <tr><th>Anexos</th><td><?= $e(implode(', ', array_column(json_decode($message['attachments'], true), 'name'))) ?></td></tr>
<?php endif; ?>
<?php if ($message['last_error']): ?>
        <tr><th>Último erro</th><td><?= $e($message['last_error']) ?></td></tr>
<?php endif; ?>
    </table>

<?php if ($message['body_html'] === null): ?>
    <p class="mail-message-note"><em>Conteúdo não guardado: este e-mail tem um link de acesso (ex.: redefinição de senha) e o corpo é apagado depois do envio.</em></p>
<?php elseif ($message['is_private']): ?>
    <p class="mail-message-note"><em>Conteúdo oculto: este e-mail tem um link de acesso. Ele é apagado depois do envio.</em></p>
<?php else: ?>
    <h3>HTML</h3>
    <iframe class="mail-preview" sandbox srcdoc="<?= $e(str_replace('cid:' . MailService::LOGO_CID, Config::mediaUrl(Config::get('store/info/logo', $message['website_id'] === null ? null : (int) $message['website_id'])), $message['body_html'])) ?>"></iframe>
    <h3>Texto</h3>
    <pre class="mail-text"><?= $e($message['body_text']) ?></pre>
<?php endif; ?>

    <div class="mail-message-actions">
<?php if ($status === MailService::PENDING): ?>
        <form method="post" action="/admin/mail/cancel" data-confirm="Cancelar o envio deste e-mail?" data-confirm-button="Cancelar envio">
            <?= \Iter\Core\FormKey::field() ?>
            <input type="hidden" name="message_id" value="<?= (int) $message['message_id'] ?>">
            <button type="submit">Cancelar envio</button>
        </form>
<?php elseif (!$message['is_private']): ?>
        <form method="post" action="/admin/mail/resend">
            <?= \Iter\Core\FormKey::field() ?>
            <input type="hidden" name="message_id" value="<?= (int) $message['message_id'] ?>">
            <button type="submit">Reenviar</button>
        </form>
<?php endif; ?>
        <a href="/admin/mail/index">← Voltar para e-mails enviados</a>
    </div>
</section>
