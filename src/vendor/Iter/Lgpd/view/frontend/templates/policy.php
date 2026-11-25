<?php
$e = static fn ($v) => htmlspecialchars((string) $v);
$date = static fn ($v) => $v ? date('d/m/Y H:i', strtotime((string) $v)) : '';
?>
<section class="lgpd-policy">
    <div class="admin-page-head">
        <div>
            <h2>Política de privacidade</h2>
            <p class="admin-list-summary">O texto que o app e a loja web mostram (query <code>privacy</code>). Escreva e salve quantas vezes quiser: os clientes só veem a versão publicada. Cada publicação vira uma versão nova, guardada como foi, e o app pede o aceite de novo.</p>
        </div>
    </div>
<?php if (count($websites) > 1): ?>
    <form method="get" action="/admin/lgpdpolicy/index" class="admin-filters">
        <select name="website_id" onchange="this.form.submit()">
    <?php foreach ($websites as $value => $label): ?>
            <option value="<?= (int) $value ?>" <?= (int) $value === $websiteId ? 'selected' : '' ?>><?= $e($label) ?></option>
    <?php endforeach; ?>
        </select>
        <button type="submit">Trocar website</button>
    </form>
<?php endif; ?>

    <table class="mail-message-info">
        <tr><th>Em vigor</th><td><?= $current ? 'Versão ' . (int) $current['version'] . ', publicada em ' . $e($date($current['published_at'])) : '<span class="admin-status admin-status-pending_payment">Nenhuma versão publicada</span> O app e a loja web ainda não têm política para mostrar.' ?></td></tr>
        <tr><th>Encarregado (DPO)</th><td><?= $dpo['email'] !== '' ? $e(trim($dpo['name'] . ' — ' . $dpo['email'], ' —')) : '<span class="admin-status admin-status-pending_payment">Não informado</span>' ?> <small><a href="/admin/config/index?website_id=<?= (int) $websiteId ?>#config-group-lgpd">Configurações → LGPD</a></small></td></tr>
    </table>

    <h3><?= $draft['is_draft'] ? 'Rascunho' : ($current ? 'Nova versão' : 'Primeira versão') ?></h3>
    <p class="admin-list-summary"><?= $draft['is_draft'] ? 'Rascunho salvo em ' . $e($date($draft['updated_at'])) . ', ainda não publicado.' : ($current ? 'Começa com o texto da versão em vigor.' : '') ?> Texto puro: uma linha em branco separa os parágrafos. O encarregado e a lista de operadores também vão pela API, para o app mostrar junto.</p>
    <form method="post" action="/admin/lgpdpolicy/save" class="lgpd-policy-form">
        <?= \Iter\Core\FormKey::field() ?>
        <input type="hidden" name="website_id" value="<?= (int) $websiteId ?>">
        <textarea name="content" rows="22" maxlength="<?= (int) $maxLength ?>"><?= $e($draft['content']) ?></textarea>
        <details class="lgpd-policy-help">
            <summary>Lista de operadores para colar (do Inventário)</summary>
            <textarea rows="<?= min(12, 3 + substr_count($operatorsText, "\n")) ?>" readonly onfocus="this.select()"><?= $e($operatorsText) ?></textarea>
        </details>
        <div class="lgpd-policy-actions">
            <button type="submit" name="action" value="draft">Salvar rascunho</button>
            <button type="submit" name="action" value="publish" class="admin-button-primary" data-confirm="Publicar como versão <?= (int) ($current['version'] ?? 0) + 1 ?>? Ela não pode ser alterada depois, e os clientes vão aceitar de novo." data-confirm-button="Publicar">Publicar versão <?= (int) ($current['version'] ?? 0) + 1 ?></button>
        </div>
    </form>

    <h3>Versões publicadas</h3>
<?php if (!$versions): ?>
    <p>Nenhuma ainda.</p>
<?php else: ?>
    <table class="lgpd-policy-versions">
        <thead><tr><th>Versão</th><th>Publicada em</th><th>Por</th><th>Aceites</th><th></th></tr></thead>
        <tbody>
    <?php foreach ($versions as $v): ?>
            <tr>
                <td><?= (int) $v['version'] ?><?= $current && (int) $current['version'] === (int) $v['version'] ? ' <span class="admin-status admin-status-on">em vigor</span>' : '' ?></td>
                <td><?= $e($date($v['published_at'])) ?></td>
                <td><?= $e($v['admin_name'] ?? '—') ?></td>
                <td><?= (int) $v['accepts'] ?></td>
                <td class="admin-row-action"><a class="admin-button" href="/admin/lgpdpolicy/view?id=<?= (int) $v['policy_id'] ?>">Ler</a></td>
            </tr>
    <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</section>
