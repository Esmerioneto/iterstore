<?php
$e = static fn ($v) => htmlspecialchars((string) $v);
?>
<section class="lgpd-inventory">
    <div class="admin-page-head">
        <div>
            <h2>Inventário de dados pessoais</h2>
            <p class="admin-list-summary">O que o sistema guarda ou repassa, para quê, com qual base legal (LGPD, art. 7º), com quem e por quanto tempo. Montado a partir dos módulos ligados: ligar ou desligar um módulo muda esta lista. Os prazos marcados com "Configurações" mostram o valor em uso.</p>
        </div>
    </div>
    <table class="lgpd-inventory-list">
        <thead>
            <tr><th>Atividade</th><th>Titulares</th><th>Dados</th><th>Finalidade</th><th>Base legal</th><th>Compartilhado com</th><th>Prazo de guarda</th></tr>
        </thead>
        <tbody>
<?php if (!$activities): ?>
            <tr><td colspan="7" class="admin-empty">Nenhum módulo ligado declara dados pessoais.</td></tr>
<?php endif; ?>
<?php foreach ($activities as $a): ?>
            <tr>
                <td><strong><?= $e($a['activity']) ?></strong><br><small><?= $e($a['module']) ?></small></td>
                <td><?= $e($a['subjects']) ?></td>
                <td><?= $e(implode(', ', (array) $a['data'])) ?></td>
                <td><?= $e($a['purpose']) ?></td>
                <td><?= $e(implode('; ', array_map(static fn ($b) => $bases[$b] ?? $b, (array) $a['basis']))) ?></td>
                <td><?= $a['operators'] ?? [] ? $e(implode('; ', array_map(static fn ($o) => $o['name'] . ' (' . $o['country'] . ')', $a['operators']))) : '<small>Ninguém</small>' ?></td>
                <td><?= $e($a['retention_now']) ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>

    <h3>Operadores</h3>
    <p class="admin-list-summary">Quem recebe dados pessoais em nome da loja. Fora do Brasil é transferência internacional (art. 33) e precisa constar na política de privacidade. Para incluir quem não está no sistema (hospedagem, contador…), use <a href="/admin/config/index#config-group-lgpd">Configurações → LGPD</a>.</p>
    <table class="lgpd-inventory-list">
        <thead>
            <tr><th>Operador</th><th>País</th><th>Para quê</th><th>Origem</th></tr>
        </thead>
        <tbody>
<?php if (!$operators): ?>
            <tr><td colspan="4" class="admin-empty">Nenhum operador.</td></tr>
<?php endif; ?>
<?php foreach ($operators as $op): $abroad = \Iter\Lgpd\Model\Inventory::isAbroad($op['country']); ?>
            <tr>
                <td><strong><?= $e($op['name']) ?></strong></td>
                <td><?= $e($op['country']) ?><?= $abroad ? ' <span class="admin-status admin-status-pending_payment">internacional</span>' : '' ?></td>
                <td><?= $e(implode('; ', array_filter($op['purposes']))) ?></td>
                <td><small><?= $e(implode(', ', $op['modules'])) ?></small></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>

    <h3>Texto para a política de privacidade</h3>
    <p class="admin-list-summary">A lista de operadores pronta para colar na política. Gerada agora, a partir da tabela acima.</p>
    <textarea class="lgpd-policy-text" rows="<?= min(20, 3 + count($operators)) ?>" readonly onfocus="this.select()"><?= $e($policyText) ?></textarea>
</section>
