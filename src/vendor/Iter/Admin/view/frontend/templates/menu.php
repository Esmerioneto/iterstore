<?php $e = static fn ($v) => htmlspecialchars((string) $v); ?>
<nav class="admin-menu" aria-label="Menu">
    <ul>
<?php foreach ($items as $item): ?>
<?php if (!empty($item['children'])): ?>
        <li>
            <?php /* accordion: details with the same name open one at a time (native) */ ?>
            <details name="admin-menu"<?= $item['open'] ? ' open' : '' ?>>
                <summary><?= $e($item['label']) ?></summary>
                <ul class="admin-submenu">
<?php foreach ($item['children'] as $child): ?>
                    <li><a href="<?= $e($child['url']) ?>"<?= $child['current'] ? ' aria-current="page"' : '' ?>><?= $e($child['label']) ?></a></li>
<?php endforeach; ?>
                </ul>
            </details>
        </li>
<?php else: ?>
        <li><a class="admin-menu-top" href="<?= $e($item['url']) ?>"<?= $item['current'] ? ' aria-current="page"' : '' ?>><?= $e($item['label']) ?></a></li>
<?php endif; ?>
<?php endforeach; ?>
    </ul>
</nav>
