<?php
$e = static fn ($v) => htmlspecialchars((string) $v);
?>
<section class="report-home">
    <div class="admin-page-head">
        <div>
            <h2>Relatórios</h2>
            <p class="admin-list-summary">Escolha um relatório. Todos têm período, filtros, totais e exportação em CSV, Excel e PDF.</p>
        </div>
    </div>
<?php if (!$groups): ?>
    <p class="admin-empty">Seu perfil não tem acesso a nenhum relatório.</p>
<?php endif; ?>
    <div class="report-groups">
<?php foreach ($groups as $group => [$title, $subtitle]): ?>
        <section class="report-group">
            <h3><?= $e($title) ?></h3>
            <p><?= $e($subtitle) ?></p>
            <ul>
    <?php foreach ($reports[$group] as $info): ?>
                <li><a href="/admin/report/view?code=<?= $e(rawurlencode($info['code'])) ?>"><strong><?= $e($info['title']) ?></strong><span><?= $e($info['description']) ?></span></a></li>
    <?php endforeach; ?>
            </ul>
        </section>
<?php endforeach; ?>
    </div>
</section>
