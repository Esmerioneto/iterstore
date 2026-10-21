<?php $e = static fn ($v): string => htmlspecialchars((string) $v); ?>
<section class="backup-kit">
    <div class="admin-page-head">
        <h2>Kit de recuperação do backup</h2>
    </div>
<?php if ($kit === null): ?>
    <p class="backup-note backup-note-warning">O kit abre todas as cópias do backup e as senhas salvas nas Configurações. Abra só para guardar no gerenciador de senhas da empresa e na cópia impressa. A abertura fica no log de operações.</p>
    <form method="post" action="/admin/backup/kitpost" class="backup-kit-unlock" autocomplete="off">
        <?= \Iter\Core\FormKey::field() ?>
        <label>Sua senha do painel <input type="password" name="password" autocomplete="current-password" required autofocus></label>
        <button type="submit" class="admin-button-primary">Mostrar o kit</button>
    </form>
<?php else: ?>
    <p class="backup-note backup-note-warning"><b>Guarde em dois lugares.</b> 1) No gerenciador de senhas da empresa, num cofre que duas pessoas acessam. 2) Impresso, em envelope lacrado, fora do local do servidor. Nunca por e-mail, WhatsApp, num arquivo solto ou no próprio bucket do backup.</p>
    <table class="backup-info">
        <tr><th>Senha do backup (restic)</th><td><code><?= $e($kit['password']) ?></code></td></tr>
        <tr><th>Chave do src/etc/env.ini</th><td><code>crypt_key="<?= $e($kit['crypt_key']) ?>"</code><br><small>Decifra as senhas salvas nas Configurações (Mercado Pago, SMTP, chave do bucket).</small></td></tr>
        <tr><th>Destino</th><td><?= $e($destination) ?><?= $key_id !== '' ? '<br><small>ID da chave: ' . $e($key_id) . '. A chave secreta não vai no kit: crie outra no painel do provedor.</small>' : '' ?></td></tr>
        <tr><th>Gerado em</th><td><?= $e(date('d/m/Y H:i')) ?></td></tr>
    </table>
    <h3>Como restaurar num servidor novo</h3>
    <ol class="backup-kit-steps">
        <li>Instale PHP, PostgreSQL, restic e git; clone o código.</li>
        <li><code>export RESTIC_REPOSITORY=…</code> (o destino acima) e <code>export RESTIC_PASSWORD=…</code> (a senha acima); no B2/S3, também a chave do bucket.</li>
        <li><code>restic restore latest --target /tmp/restaurado</code></li>
        <li>Banco vazio + <code>grep -v '^SET transaction_timeout' /tmp/restaurado<?= $e($dump_file) ?> | psql -v ON_ERROR_STOP=1 -d &lt;banco&gt;</code></li>
        <li>Copie <code>public/media</code> e <code>src/etc</code> do restaurado (o <code>env.ini</code> tem de ser este do kit), <code>git checkout</code> do <code>commit.txt</code>, <code>composer install</code>, <code>php bin/migrate.php up</code>.</li>
    </ol>
    <div class="backup-kit-actions">
        <form method="post" action="/admin/backup/kitsaved">
            <?= \Iter\Core\FormKey::field() ?>
            <button type="submit" class="admin-button-primary">Já guardei nos dois lugares</button>
        </form>
        <a href="/admin/backup/index">← Voltar para o Backup</a>
    </div>
<?php endif; ?>
</section>
