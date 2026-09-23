<nav class="admin-breadcrumb">
<?php foreach ($items as $index => $item): ?>
    <?php if ($index > 0): ?><span> / </span><?php endif; ?>
    <?php if (isset($item['url']) && $index < count($items) - 1): ?>
        <a href="<?= htmlspecialchars($item['url']) ?>"><?= htmlspecialchars($item['label']) ?></a>
    <?php else: ?>
        <span><?= htmlspecialchars($item['label']) ?></span>
    <?php endif; ?>
<?php endforeach; ?>
</nav>
