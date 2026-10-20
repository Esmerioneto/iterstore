<section class="catalog-cost-import">
    <div class="admin-page-head">
        <h2>Importar custos</h2>
        <a class="admin-button" href="/admin/productcost/template">Baixar modelo (.csv)</a>
    </div>
<?php if ($errors): ?>
    <div class="catalog-cost-import-errors">
        <strong>Nenhum custo foi salvo. Corrija estas linhas e envie o arquivo de novo:</strong>
        <ul>
<?php foreach ($errors as $error): ?>
            <li><?= htmlspecialchars($error) ?></li>
<?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
    <p class="catalog-cost-import-note">Envie uma planilha <strong>.csv</strong> ou <strong>.xlsx</strong> com as colunas <code>SKU</code> e <code>Custo</code> na primeira linha (as outras colunas são ignoradas). O modelo traz todos os produtos e variações com o custo de hoje: altere a coluna Custo e envie.</p>
    <ul class="catalog-cost-import-rules">
        <li>Custo como <code>12,50</code>, <code>1.234,56</code> ou <code>12.5</code>; <code>0</code> é custo zero.</li>
        <li>Custo vazio mantém o custo do produto.</li>
        <li>Produto com variações: o custo vai na linha de cada variação.</li>
        <li>Se alguma linha tiver problema, nada é salvo e a lista dos erros aparece aqui. Até <?= number_format($max_lines, 0, ',', '.') ?> linhas por arquivo.</li>
        <li>Pedidos já feitos não mudam: o custo de cada item fica gravado no pedido. Cada custo alterado fica no log de operações do produto.</li>
    </ul>
    <form method="post" action="/admin/productcost/import" enctype="multipart/form-data" class="catalog-cost-import-form">
        <?= \Iter\Core\FormKey::field() ?>
        <input type="file" name="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
        <button type="submit" class="admin-button admin-button-primary">Importar</button>
        <a class="admin-button" href="/admin/product/index">Cancelar</a>
    </form>
</section>
