<?php
$e = static fn ($v) => htmlspecialchars((string) $v);
$js = '/assets/admin/sales-dashboard.js';
?>
<section class="sales-dash" id="sales-dash" data-url="/admin/salesdashboard/data">
    <div class="dash-bar">
        <h2>Painel de vendas</h2>
        <div class="seg" id="dash-period" role="group" aria-label="Período">
<?php foreach ($periods as $days): ?>
            <button type="button" data-v="<?= (int) $days ?>" aria-pressed="<?= $days === 30 ? 'true' : 'false' ?>"><?= (int) $days ?> dias</button>
<?php endforeach; ?>
        </div>
<?php if (count($websites) > 1): ?>
        <select id="dash-website" aria-label="Website">
            <option value="">Todos os websites</option>
    <?php foreach ($websites as $id => $name): ?>
            <option value="<?= (int) $id ?>"><?= $e($name) ?></option>
    <?php endforeach; ?>
        </select>
<?php endif; ?>
        <span class="dash-updated" id="dash-updated" aria-live="polite">Carregando…</span>
    </div>

    <div class="dash-kpis" id="dash-kpis"></div>

    <div class="dash-row dash-row-a">
        <section class="dash-card">
            <div class="dash-card-head">
                <div><h3>Evolução dos pedidos</h3> <span class="dash-sub" id="dash-evo-sub"></span></div>
                <div class="dash-tools">
                    <div class="seg" id="dash-measure" role="group" aria-label="Medida"><button type="button" data-v="orders" aria-pressed="true">Pedidos</button><button type="button" data-v="revenue" aria-pressed="false">Faturamento</button><button type="button" data-v="status" aria-pressed="false">Situação</button></div>
                    <div class="seg" id="dash-grain" role="group" aria-label="Agrupar por"><button type="button" data-v="day" aria-pressed="true">Dia</button><button type="button" data-v="week" aria-pressed="false">Semana</button><button type="button" data-v="month" aria-pressed="false">Mês</button></div>
                    <button type="button" class="dash-as-table" data-table="evo">tabela</button>
                </div>
            </div>
            <div class="dash-plot" id="dash-evo"></div>
        </section>
        <section class="dash-card">
            <div class="dash-card-head"><div><h3>Horário que mais vende</h3> <span class="dash-sub" id="dash-heat-sub"></span></div><button type="button" class="dash-as-table" data-table="heat">tabela</button></div>
            <div class="dash-plot" id="dash-heat"></div>
        </section>
    </div>

    <div class="dash-row dash-row-b">
        <section class="dash-card">
            <div class="dash-card-head"><div><h3>Pagamento por dia</h3> <span class="dash-sub">· últimos 14 dias</span></div><button type="button" class="dash-as-table" data-table="pay">tabela</button></div>
            <div class="dash-legend" id="dash-pay-legend"></div>
            <div class="dash-plot" id="dash-pay"></div>
        </section>
        <section class="dash-card">
            <div class="dash-card-head"><div><h3>Top 10 produtos</h3> <span class="dash-sub">· unidades</span></div><button type="button" class="dash-as-table" data-table="top">tabela</button></div>
            <div class="dash-plot" id="dash-top"></div>
        </section>
        <section class="dash-card">
            <div class="dash-card-head"><div><h3>Categorias</h3> <span class="dash-sub">· faturamento</span></div><button type="button" class="dash-as-table" data-table="cats">tabela</button></div>
            <div class="dash-split">
                <div class="dash-plot" id="dash-cats"></div>
                <div>
                    <div class="dash-card-head"><div><h3>Canal de venda</h3> <span class="dash-sub">· faturamento</span></div><button type="button" class="dash-as-table" data-table="chan">tabela</button></div>
                    <div class="dash-plot dash-plot-share" id="dash-chan"></div>
                </div>
            </div>
        </section>
    </div>
    <div class="dash-tip" id="dash-tip"></div>
    <dialog id="dash-dialog"><h3 id="dash-dialog-title"></h3><div id="dash-dialog-body"></div><form method="dialog"><button>Fechar</button></form></dialog>
</section>
<script src="<?= $js ?>?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . $js) ?>" defer></script>
