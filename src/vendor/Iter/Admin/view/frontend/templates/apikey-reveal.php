<?php $e = static fn ($v): string => htmlspecialchars((string) $v); ?>
<section class="admin-apikey-reveal">
    <div class="admin-page-head">
        <h2>Chave de "<?= $e($key['name']) ?>"</h2>
    </div>
<?php if ($plain === null): ?>
    <p class="admin-apikey-status">Quem tem a chave usa a API como o aplicativo ou site. Confirme sua senha do painel para vê-la; a abertura fica no log de operações.</p>
    <form method="post" action="/admin/apikey/revealpost" class="admin-filters" autocomplete="off">
        <?= \Iter\Core\FormKey::field() ?>
        <input type="hidden" name="key_id" value="<?= (int) $key['key_id'] ?>">
        <input type="password" name="password" placeholder="Sua senha do painel" autocomplete="current-password" required autofocus>
        <button type="submit" class="admin-button-primary">Ver chave</button>
    </form>
<?php elseif ($plain === ''): ?>
    <p class="admin-apikey-status admin-apikey-status-off">A chave não pôde ser decifrada (a chave do <code>src/etc/env.ini</code> mudou?). Crie uma nova e revogue esta.</p>
<?php else: ?>
    <div class="admin-apikey-new">
        <code data-copy><?= $e($plain) ?></code>
        <div><button type="button" class="admin-button-primary" data-copy-button>Copiar</button></div>
        <small>O aplicativo ou site envia esta chave no cabeçalho <code>X-Api-Key</code> de cada chamada à API.</small>
    </div>
<?php endif; ?>
    <p><a href="/admin/apikey/index">← Voltar para Chaves da API</a></p>
</section>
