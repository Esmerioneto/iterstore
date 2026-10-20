<?php
use Iter\Admin\View\Sort;
use Iter\Report\Model\Export;
use Iter\Report\Model\Format;
use Iter\Report\Model\ReportService;
use Iter\Report\View\ReportView;

$e = static fn ($v) => htmlspecialchars((string) $v);
$info = $report->info;
$common = $report->common();
$numeric = ['money', 'pct', 'int', 'qty', 'age'];
?>
<section class="report-view">
    <div class="admin-page-head">
        <div>
            <h2><?= $e($info['title']) ?></h2>
            <p class="admin-list-summary"><?= $e((ReportService::GROUPS[$info['group']][0] ?? '') . ' · ' . $info['description']) ?></p>
        </div>
        <div class="report-export" role="group" aria-label="Exportar">
            <span>Exportar</span>
<?php // they send the filter form: the file has what is on the screen, even a date changed without "Filtrar" ?>
<?php foreach (Export::LABELS as $format => $label): ?>
            <button type="submit" form="report-filters" formaction="/admin/report/export" name="format" value="<?= $e($format) ?>" class="admin-button"><?= $label ?></button>
<?php endforeach; ?>
        </div>
    </div>
<?php if ($errors): ?>
    <div class="admin-messages">
    <?php foreach ($errors as $error): ?>
        <div class="message message-warning" data-label="Aviso"><?= $e($error) ?></div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
    <form method="get" action="/admin/report/view" class="admin-filters" id="report-filters" data-report-period>
        <input type="hidden" name="code" value="<?= $e($info['code']) ?>">
<?php if (in_array('period', $common, true)): ?>
        <select name="preset" aria-label="Período">
    <?php foreach ($presets as $key => $preset): ?>
            <option value="<?= $e($key) ?>" <?= $f['preset'] === (string) $key ? 'selected' : '' ?><?= $preset['dates'] ? ' data-from="' . $e($preset['dates'][0]) . '" data-to="' . $e($preset['dates'][1]) . '"' : '' ?>><?= $e($preset['label']) ?></option>
    <?php endforeach; ?>
        </select>
        <label>De <input type="date" name="from" value="<?= $e($f['from']) ?>" required></label>
        <label>até <input type="date" name="to" value="<?= $e($f['to']) ?>" required></label>
<?php else: // no period: the "sent" marker the checkboxes need (ReportService::filters) ?>
        <input type="hidden" name="preset" value="month">
<?php endif; ?>
<?php if (in_array('website_id', $common, true) && count($websites) > 1): ?>
        <select name="website_id" aria-label="Website">
            <option value="">Todos os websites</option>
    <?php foreach ($websites as $id => $name): ?>
            <option value="<?= (int) $id ?>" <?= $f['website_id'] === (int) $id ? 'selected' : '' ?>><?= $e($name) ?></option>
    <?php endforeach; ?>
        </select>
<?php endif; ?>
<?php if (in_array('sales_channel', $common, true)): ?>
        <select name="sales_channel" aria-label="Canal de venda">
            <option value="">Todos os canais</option>
    <?php foreach ($channels as $channel): ?>
            <option value="<?= $e($channel) ?>" <?= $f['sales_channel'] === $channel ? 'selected' : '' ?>><?= $e($channel) ?></option>
    <?php endforeach; ?>
        </select>
<?php endif; ?>
<?php if (in_array('payment_method', $common, true)): ?>
        <select name="payment_method" aria-label="Forma de pagamento">
            <option value="">Todas as formas de pagamento</option>
    <?php foreach ($payments as $code => $label): ?>
            <option value="<?= $e($code) ?>" <?= $f['payment_method'] === (string) $code ? 'selected' : '' ?>><?= $e($label) ?></option>
    <?php endforeach; ?>
        </select>
<?php endif; ?>
<?php foreach ($report->filters() as $key => $filter): ?>
    <?php if (($filter['type'] ?? 'select') === 'select'): ?>
        <select name="<?= $e($key) ?>" aria-label="<?= $e($filter['label']) ?>">
        <?php foreach ($filter['options'] as $value => $label): ?>
            <option value="<?= $e($value) ?>" <?= $f[$key] === (string) $value ? 'selected' : '' ?>><?= $e($label) ?></option>
        <?php endforeach; ?>
        </select>
    <?php elseif ($filter['type'] === 'checkbox'): ?>
        <label class="report-check"><input type="checkbox" name="<?= $e($key) ?>" value="1" <?= $f[$key] ? 'checked' : '' ?>> <?= $e($filter['label']) ?></label>
    <?php elseif ($filter['type'] === 'number'): ?>
        <label><?= $e($filter['label']) ?> <input type="number" name="<?= $e($key) ?>" value="<?= (int) $f[$key] ?>" min="0" class="report-number"></label>
    <?php else: ?>
        <input type="search" name="<?= $e($key) ?>" value="<?= $e($f[$key]) ?>" placeholder="<?= $e($filter['placeholder'] ?? $filter['label']) ?>" aria-label="<?= $e($filter['label']) ?>">
    <?php endif; ?>
<?php endforeach; ?>
        <?= Sort::hidden() ?>
        <button type="submit">Filtrar</button>
    </form>

<?php if ($tiles): ?>
    <div class="report-tiles">
    <?php foreach ($tiles as $tile): ?>
        <div class="report-tile<?= isset($tile['tone']) ? ' report-tile-' . $e($tile['tone']) : '' ?>">
            <small><?= $e($tile['label']) ?></small>
            <strong><?= $e($tile['value']) ?></strong>
        <?php if (($tile['note'] ?? '') !== ''): ?>
            <span><?= $e($tile['note']) ?></span>
        <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($chart && $chart['bars']): ?>
    <?php $max = max(array_column($chart['bars'], 'value')) ?: 1; $withPart = array_filter(array_column($chart['bars'], 'part'), static fn ($p) => $p !== null) !== []; ?>
    <figure class="report-chart">
        <figcaption><?= $e($chart['title']) ?><?php if ($withPart): ?> <span><i class="report-chart-total"></i>Faturamento <i class="report-chart-part"></i>Margem</span><?php endif; ?></figcaption>
        <div class="report-chart-bars" style="--bars: <?= count($chart['bars']) ?>">
    <?php foreach ($chart['bars'] as $bar): ?>
            <div class="report-chart-bar" title="<?= $e($bar['label'] . ': ' . Format::show('money', $bar['value']) . ($bar['part'] !== null ? ' · margem ' . Format::show('money', $bar['part']) : '')) ?>">
                <div class="report-chart-total" style="height: <?= round(max(0, $bar['value']) / $max * 100, 2) ?>%">
        <?php if ($bar['part'] !== null && $bar['value'] > 0): ?>
                    <div class="report-chart-part" style="height: <?= round(max(0, min($bar['part'], $bar['value'])) / $bar['value'] * 100, 2) ?>%"></div>
        <?php endif; ?>
                </div>
                <small><?= $e($bar['label']) ?></small>
            </div>
    <?php endforeach; ?>
        </div>
    </figure>
<?php endif; ?>

    <div class="report-table">
        <table>
            <thead>
                <tr>
<?php foreach ($columns as $key => $column): ?>
                    <?= str_replace('<th class="', '<th class="' . (in_array($column['type'], $numeric, true) ? 'report-num ' : ''), Sort::th((string) $key, $column['label'], $sort, $dir)) ?>
<?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
<?php if (!$rows): ?>
                <tr><td colspan="<?= count($columns) ?>" class="admin-empty">Nada encontrado com estes filtros.</td></tr>
<?php endif; ?>
<?php foreach ($rows as $row): ?>
                <tr>
    <?php foreach ($columns as $key => $column): ?>
        <?php $text = Format::show($column['type'], $row[$key] ?? null); ?>
                    <td<?= in_array($column['type'], $numeric, true) ? ' class="report-num"' : '' ?>><?= isset($column['link']) && ($row[$key] ?? null) !== null && ($url = ReportView::link($column['link'], $row)) !== null ? '<a href="' . $e($url) . '">' . $e($text) . '</a>' : $e($text) ?></td>
    <?php endforeach; ?>
                </tr>
<?php endforeach; ?>
            </tbody>
<?php if ($rows): ?>
            <tfoot>
                <tr>
    <?php $first = true; foreach ($columns as $key => $column): ?>
                    <td<?= in_array($column['type'], $numeric, true) ? ' class="report-num"' : '' ?>><?= $first ? 'Total' : (array_key_exists($key, $totals) ? $e(Format::show($column['type'], $totals[$key])) : '') ?></td>
        <?php $first = false; ?>
    <?php endforeach; ?>
                </tr>
            </tfoot>
<?php endif; ?>
        </table>
    </div>
<?php if ($note !== ''): ?>
    <p class="report-note"><?= $e($note) ?></p>
<?php endif; ?>
    <?php \Iter\Admin\View\Pager::render($pager); ?>
</section>
