<?php
$e = static fn ($v) => htmlspecialchars((string) $v);
?>
<section class="mail-view lgpd-policy-version">
    <h2>Política de privacidade — versão <?= (int) $policy['version'] ?></h2>
    <p class="admin-list-summary"><?= $e($policy['website']) ?>, publicada em <?= $e(date('d/m/Y H:i', strtotime((string) $policy['published_at']))) ?>. Versão publicada não muda: é o texto que quem a aceitou viu.</p>
    <div class="lgpd-policy-text-view"><?= nl2br($e($policy['content'])) ?></div>
    <div class="mail-message-actions"><a href="/admin/lgpdpolicy/index?website_id=<?= (int) $policy['website_id'] ?>">← Voltar para a política</a></div>
</section>
