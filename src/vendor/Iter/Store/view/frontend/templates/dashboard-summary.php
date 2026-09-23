<?php if ($counts === null) return; ?>
<section class="admin-dashboard-card">
    <h2>Lojas</h2>
    <p>
        <a href="/admin/website/index"><?= $counts['websites'] ?> website(s)</a>
        · <a href="/admin/group/index"><?= $counts['groups'] ?> grupo(s)</a>
        · <a href="/admin/store/index"><?= $counts['stores'] ?> loja(s)</a>
    </p>
<?php if ($counts['ungrouped'] > 0): ?>
    <p><?= $counts['ungrouped'] ?> loja(s) sem grupo de loja.</p>
<?php endif; ?>
</section>
