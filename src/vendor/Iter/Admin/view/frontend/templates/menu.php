<nav class="admin-menu">
    <ul>
<?php foreach ($items as $item): ?>
<?php if (!empty($item['children'])): ?>
        <li>
            <details<?= $item['open'] ? ' open' : '' ?>>
                <summary><a href="<?= htmlspecialchars($item['url']) ?>"><?= htmlspecialchars($item['label']) ?></a></summary>
                <ul class="admin-submenu">
<?php foreach ($item['children'] as $child): ?>
                    <li><a href="<?= htmlspecialchars($child['url']) ?>"><?= htmlspecialchars($child['label']) ?></a></li>
<?php endforeach; ?>
                </ul>
            </details>
        </li>
<?php else: ?>
        <li><a href="<?= htmlspecialchars($item['url']) ?>"><?= htmlspecialchars($item['label']) ?></a></li>
<?php endif; ?>
<?php endforeach; ?>
    </ul>
</nav>
