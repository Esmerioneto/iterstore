<?php if ($counts === null && !$chart) return; ?>
<section class="admin-dashboard-card">
    <h2>Vendas</h2>
<?php if ($counts !== null): ?>
    <p>
        <a href="/admin/order/index?from=<?= date('Y-m-d') ?>"><?= (int) $counts['today'] ?> pedido(s) hoje</a>
        · <a href="/admin/order/index?status=pending_payment"><?= (int) $counts['pending_payment'] ?> a pagar</a>
        · <a href="/admin/order/index?status=processing"><?= (int) $counts['processing'] ?> a enviar</a>
        · R$ <?= number_format((float) $counts['month'], 2, ',', '.') ?> no mês
    </p>
<?php endif; ?>
<?php if ($chart): ?>
    <p><a href="/admin/salesdashboard/index">Ver o painel de vendas →</a></p>
<?php endif; ?>
</section>
