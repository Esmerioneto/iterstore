<?php if ($counts === null) return; ?>
<section class="admin-dashboard-card">
    <h2>Clientes</h2>
    <p>
        <a href="/admin/customer/index"><?= $counts['total'] ?> cliente(s)</a>
        · <?= $counts['recent'] ?> novo(s) nos últimos 7 dias
        · <a href="/admin/customer/index?is_active=0"><?= $counts['inactive'] ?> inativo(s)</a>
    </p>
</section>
