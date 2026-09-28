<section class="eav-attribute-set-preview">
    <h2>Pré-visualização: <?= htmlspecialchars((string) $set['label']) ?></h2>
    <p><small>Formulário gerado a partir do conjunto. Nada é salvo aqui.</small></p>
    <div>
<?php if (empty($schema['fields'])): ?>
        <p>O conjunto não tem atributos visíveis.</p>
<?php else: ?>
        <?php require __DIR__ . '/form-fields.php'; ?>
<?php endif; ?>
    </div>
    <p><a href="/admin/attributeset/edit?id=<?= (int) $set['attribute_set_id'] ?>">Voltar ao conjunto</a></p>
</section>
