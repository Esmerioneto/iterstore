<?php
// The report as a PDF (Export::pdf, dompdf): A4 landscape; the page footer is drawn by Export
use Iter\Report\Model\Format;

$e = static fn ($v) => htmlspecialchars((string) $v);
$numeric = ['money', 'pct', 'int', 'qty', 'age'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 28px 34px 44px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8px; color: #121212; }
    .head { width: 100%; border-bottom: 1px solid #121212; padding-bottom: 8px; margin-bottom: 10px; }
    .head td { vertical-align: middle; }
    .head img { max-height: 38px; max-width: 140px; }
    .store { text-align: right; font-size: 9px; color: #484847; }
    h1 { font-size: 16px; font-weight: normal; margin: 0 0 3px; }
    .meta { color: #6e6e6b; margin: 0 0 10px; }
    .tiles { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0 -6px 12px; }
    .tiles td { border-top: 1px solid #121212; padding: 4px 0 0; vertical-align: top; }
    .tiles small { display: block; font-size: 6.5px; text-transform: uppercase; letter-spacing: .6px; color: #6e6e6b; }
    .tiles strong { display: block; font-size: 12px; font-weight: normal; margin: 2px 0; }
    .tiles span { color: #484847; font-size: 7px; }
    .tiles .ok { border-top-color: #2f7a4b; } .tiles .warn { border-top-color: #8a5f12; } .tiles .bad { border-top-color: #a33; }
    table.lines { width: 100%; border-collapse: collapse; }
    .lines thead { display: table-header-group; }
    .lines th { background: #1f1f1f; color: #fff; font-weight: bold; text-align: left; padding: 4px 5px; font-size: 7px; text-transform: uppercase; letter-spacing: .4px; }
    .lines td { padding: 3px 5px; border-bottom: 1px solid #eaeae6; }
    .lines tr { page-break-inside: avoid; }
    .lines .num { text-align: right; white-space: nowrap; }
    .lines tfoot td { font-weight: bold; background: #eeeeee; border-top: 1.5px solid #1f1f1f; border-bottom: 0; }
    .note { color: #6e6e6b; margin-top: 10px; font-style: italic; }
</style>
</head>
<body>
    <table class="head">
        <tr>
            <td><img src="<?= $e($logo) ?>" alt=""></td>
            <td class="store"><?= $e($store) ?></td>
        </tr>
    </table>
    <h1><?= $e($title) ?></h1>
    <p class="meta"><?= $e($description) ?> · <?= $e($by) ?></p>
<?php if ($tiles): ?>
    <table class="tiles">
        <tr>
    <?php foreach ($tiles as $tile): ?>
            <td class="<?= $e($tile['tone'] ?? '') ?>"><small><?= $e($tile['label']) ?></small><strong><?= $e($tile['value']) ?></strong><span><?= $e($tile['note'] ?? '') ?></span></td>
    <?php endforeach; ?>
        </tr>
    </table>
<?php endif; ?>
    <table class="lines">
        <thead>
            <tr>
<?php foreach ($columns as $column): ?>
                <th<?= in_array($column['type'], $numeric, true) ? ' class="num"' : '' ?>><?= $e($column['label']) ?></th>
<?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
<?php if (!$rows): ?>
            <tr><td colspan="<?= count($columns) ?>">Nada encontrado com estes filtros.</td></tr>
<?php endif; ?>
<?php foreach ($rows as $row): ?>
            <tr>
    <?php foreach ($columns as $key => $column): ?>
                <td<?= in_array($column['type'], $numeric, true) ? ' class="num"' : '' ?>><?= $e(Format::show($column['type'], $row[$key] ?? null)) ?></td>
    <?php endforeach; ?>
            </tr>
<?php endforeach; ?>
        </tbody>
<?php if ($rows): ?>
        <tfoot>
            <tr>
    <?php foreach (array_values($columns) as $i => $column): ?>
                <td<?= in_array($column['type'], $numeric, true) ? ' class="num"' : '' ?>><?= $e($total[$i] ?? '') ?></td>
    <?php endforeach; ?>
            </tr>
        </tfoot>
<?php endif; ?>
    </table>
<?php if ($note !== ''): ?>
    <p class="note"><?= $e($note) ?></p>
<?php endif; ?>
</body>
</html>
