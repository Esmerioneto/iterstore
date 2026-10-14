<?php
$e = static fn ($v) => htmlspecialchars((string) $v);
$badge = static fn (int $n) => $n > 0 ? '<span class="admin-badge" aria-label="' . $n . ' pendente(s)">' . ($n > 99 ? '99+' : $n) . '</span>' : '';
?>
<nav class="admin-menu" id="admin-menu" aria-label="Menu">
    <ul>
<?php foreach ($items as $item): ?>
<?php if (!empty($item['children'])): ?>
        <li>
            <?php /* accordion: details with the same name open one at a time (native) */ ?>
            <details name="admin-menu"<?= $item['open'] ? ' open' : '' ?>>
                <summary><?= $e($item['label']) ?><?= $badge($item['badge']) ?></summary>
                <ul class="admin-submenu">
<?php foreach ($item['children'] as $child): ?>
                    <li><a href="<?= $e($child['url']) ?>"<?= $child['current'] ? ' aria-current="page"' : '' ?>><?= $e($child['label']) ?><?= $badge($child['badge']) ?></a></li>
<?php endforeach; ?>
                </ul>
            </details>
        </li>
<?php else: ?>
        <li><a class="admin-menu-top" href="<?= $e($item['url']) ?>"<?= $item['current'] ? ' aria-current="page"' : '' ?>><?= $e($item['label']) ?><?= $badge($item['badge']) ?></a></li>
<?php endif; ?>
<?php endforeach; ?>
    </ul>
</nav>
