<?php $e = static fn ($v): string => htmlspecialchars((string) $v); ?>
<section class="admin-apikey-list">
    <div class="admin-page-head">
        <h2>Chaves da API</h2>
    </div>
<?php if ($new): ?>
    <div class="admin-apikey-new">
        <strong>Chave de "<?= $e($new['name']) ?>": copie agora, ela não aparece de novo.</strong>
        <code data-copy><?= $e($new['key']) ?></code>
        <small>O aplicativo ou site envia esta chave no cabeçalho <code>X-Api-Key</code> de cada chamada à API.</small>
    </div>
<?php endif; ?>
    <p class="admin-apikey-status admin-apikey-status-<?= $required ? 'on' : 'off' ?>">
<?php if ($required): ?>
        A chave é <strong>exigida</strong>: chamadas à API sem uma chave ativa são recusadas (o painel e as integrações com token de admin continuam funcionando).
<?php else: ?>
        A chave ainda <strong>não é exigida</strong>: qualquer programa pode ler o catálogo pela API. Crie uma chave para cada aplicativo e site, configure-os para enviá-la e depois ligue "Exigir chave do aplicativo" em <a href="/admin/config/index">Configurações → API</a>.
<?php endif; ?>
    </p>
    <form method="post" action="/admin/apikey/create" class="admin-filters">
        <?= \Iter\Core\FormKey::field() ?>
        <input type="search" name="name" maxlength="100" placeholder="Nome do aplicativo ou site, ex.: App Jardim Vera (Android e iOS)" required>
        <button type="submit" class="admin-button-primary">+ Nova chave</button>
    </form>
    <table>
        <thead>
            <tr><th>Nome</th><th>Chave</th><th>Situação</th><th>Criada</th><th>Último uso</th><th></th></tr>
        </thead>
        <tbody>
<?php if (!$keys): ?>
            <tr><td colspan="6" class="admin-empty">Nenhuma chave criada.</td></tr>
<?php endif; ?>
<?php foreach ($keys as $k): ?>
            <tr>
                <td><?= $e($k['name']) ?></td>
                <td><code><?= $e($k['key_hint']) ?></code></td>
                <td><span class="admin-status admin-status-<?= $k['is_active'] ? 'on' : 'off' ?>"><?= $k['is_active'] ? 'Ativa' : 'Revogada em ' . date('d/m/Y H:i', strtotime($k['revoked_at'])) ?></span></td>
                <td><?= date('d/m/Y H:i', strtotime($k['created_at'])) ?><?= $k['created_by_name'] ? '<br><small>' . $e($k['created_by_name']) . '</small>' : '' ?></td>
                <td><?= $k['last_used_at'] ? date('d/m/Y H:i', strtotime($k['last_used_at'])) : 'Nunca' ?></td>
                <td class="admin-row-action">
<?php if ($k['is_active']): ?>
                    <form method="post" action="/admin/apikey/revoke" data-confirm="Revogar a chave &quot;<?= $e($k['name']) ?>&quot;? O aplicativo que a usa para de funcionar na hora." data-confirm-button="Revogar">
                        <?= \Iter\Core\FormKey::field() ?>
                        <input type="hidden" name="key_id" value="<?= (int) $k['key_id'] ?>">
                        <button type="submit">Revogar</button>
                    </form>
<?php endif; ?>
                </td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
