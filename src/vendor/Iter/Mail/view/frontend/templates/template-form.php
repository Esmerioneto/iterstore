<?php
use Iter\Mail\Model\Template;

$e = static fn ($v) => htmlspecialchars((string) $v);
$isLayout = $template['code'] === Template::LAYOUT;
?>
<section class="mail-template-form">
    <h2><?= $e($template['label']) ?></h2>
    <p><code><?= $e($template['code']) ?></code> — <?= $template['is_custom'] ? 'personalizado' : 'padrão do módulo' ?></p>
    <form method="post" action="/admin/mailtemplate/save" id="mail-template-form">
        <?= \Iter\Core\FormKey::field() ?>
        <input type="hidden" name="code" value="<?= $e($template['code']) ?>">
<?php if (!$isLayout): ?>
        <p><label>Assunto *<br><input type="text" name="subject" maxlength="998" value="<?= $e($template['subject']) ?>" required></label></p>
<?php else: ?>
        <input type="hidden" name="subject" value="<?= $e($template['subject']) ?>">
<?php endif; ?>
        <p><label>Corpo HTML *<br><textarea name="body_html" rows="16" required><?= $e($template['body_html']) ?></textarea></label></p>
<?php if (!$isLayout): ?>
        <p><label>Versão texto (opcional; vazio = gerada do HTML)<br><textarea name="body_text" rows="8"><?= $e($template['body_text']) ?></textarea></label></p>
<?php endif; ?>
        <p>
            Variáveis: <?= implode(', ', array_map(static fn ($name) => '<code>{{ ' . $e($name) . ' }}</code>', array_keys($template['vars']))) ?: 'nenhuma' ?>.
            <small><code>{{ nome }}</code> escapa o valor; <code>{{{ nome }}}</code> insere sem escapar (só para conteúdo confiável, como o <code>content</code> do layout).</small>
        </p>
        <p>
            <button type="submit">Salvar</button>
            <button type="submit" formaction="/admin/mailtemplate/preview" formtarget="mail-template-preview" formnovalidate>Pré-visualizar</button>
            <a href="/admin/mailtemplate/index">Voltar</a>
        </p>
    </form>
<?php if ($template['is_custom']): ?>
    <form method="post" action="/admin/mailtemplate/restore" data-confirm="Descartar as mudanças e voltar ao modelo do módulo?" data-confirm-button="Restaurar">
        <?= \Iter\Core\FormKey::field() ?>
        <input type="hidden" name="code" value="<?= $e($template['code']) ?>">
        <button type="submit">Restaurar padrão</button>
    </form>
<?php endif; ?>
    <h3>Pré-visualização (com valores de exemplo)</h3>
    <iframe name="mail-template-preview" class="mail-preview" sandbox="allow-same-origin" title="Pré-visualização"></iframe>
</section>
