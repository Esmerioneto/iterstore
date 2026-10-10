<?php
// The list pager, under the table (Iter\Admin\View\Pager::render): every link and the form keep the
// request's other parameters (filters, sort); the page size only when it isn't the default.
$e = static fn ($v) => htmlspecialchars((string) $v);
$path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
$keep = array_filter(array_diff_key($_GET, ['page' => 1, 'limit' => 1]), static fn ($v) => is_string($v) && $v !== '');
$links = $keep + ($pager['limit'] === $pager['sizes'][0] ? [] : ['limit' => $pager['limit']]);
$pageUrl = static fn (int $p) => $e($path . '?' . http_build_query($links + ['page' => $p]));
$from = $pager['total'] ? ($pager['page'] - 1) * $pager['limit'] + 1 : 0;
?>
    <form method="get" action="<?= $e($path) ?>" class="admin-pager">
<?php foreach ($keep as $name => $value): ?>
        <input type="hidden" name="<?= $e($name) ?>" value="<?= $e($value) ?>">
<?php endforeach; ?>
        <label>Ir para a página: <input type="number" name="page" value="<?= (int) $pager['page'] ?>" min="1" max="<?= (int) $pager['total_pages'] ?>"></label>
        <label>Mostrar linhas:
            <select name="limit" data-autosubmit>
<?php foreach ($pager['sizes'] as $size): ?>
                <option value="<?= $size ?>" <?= $size === $pager['limit'] ? 'selected' : '' ?>><?= $size ?></option>
<?php endforeach; ?>
            </select>
        </label>
        <span><?= $from ? $from . '-' . min($pager['page'] * $pager['limit'], $pager['total']) : 0 ?> de <?= (int) $pager['total'] ?></span>
        <span class="admin-pager-arrows">
<?php if ($pager['page'] > 1): ?>
            <a href="<?= $pageUrl($pager['page'] - 1) ?>" title="Página anterior" aria-label="Página anterior">&#9664;</a>
<?php else: ?>
            <span aria-disabled="true">&#9664;</span>
<?php endif; ?>
<?php if ($pager['page'] < $pager['total_pages']): ?>
            <a href="<?= $pageUrl($pager['page'] + 1) ?>" title="Próxima página" aria-label="Próxima página">&#9654;</a>
<?php else: ?>
            <span aria-disabled="true">&#9654;</span>
<?php endif; ?>
        </span>
    </form>
