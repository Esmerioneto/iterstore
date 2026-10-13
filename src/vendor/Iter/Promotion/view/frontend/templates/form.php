<?php $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); $v = $values; ?>
<section class="catalog-product-form promotion-form">
    <h2><?= $id ? 'Editar promoção' : 'Nova promoção' ?></h2>
    <p class="promotion-intro">Configure a regra de desconto, sua validade e os produtos elegíveis. As condições são reavaliadas pelo servidor sempre que o carrinho muda.</p>
    <form method="post" action="/admin/promotion/save">
        <?= \Iter\Core\FormKey::field() ?>
        <input type="hidden" name="promotion_id" value="<?= (int) $id ?>">

        <fieldset>
            <legend>Informações gerais</legend>
            <p>
                <label>Nome da promoção *<br>
                    <input type="text" name="name" maxlength="255" value="<?= $e($v['name']) ?>" required>
                </label>
                <small>Nome interno exibido na administração e no resumo do pedido.</small>
            </p>
            <p>
                <label>Descrição<br>
                    <textarea name="description" rows="3" maxlength="2000"><?= $e($v['description']) ?></textarea>
                </label>
            </p>
            <p class="promotion-check">
                <label><input type="checkbox" name="is_active" value="1" <?= $v['is_active'] ? 'checked' : '' ?>> Promoção ativa</label>
            </p>
            <p>
                <label>Forma de aplicação *<br>
                    <select name="application_type" required>
                        <option value="automatic" <?= $v['application_type'] === 'automatic' ? 'selected' : '' ?>>Automática — aplicada quando as condições forem atendidas</option>
                        <option value="coupon" <?= $v['application_type'] === 'coupon' ? 'selected' : '' ?>>Por cupom — exige código no carrinho</option>
                    </select>
                </label>
            </p>
            <div class="promotion-fields-row">
                <p>
                    <label>Tipo de desconto *<br>
                        <select name="discount_type" required>
                            <option value="percent" <?= $v['discount_type'] === 'percent' ? 'selected' : '' ?>>Percentual (%)</option>
                            <option value="fixed" <?= $v['discount_type'] === 'fixed' ? 'selected' : '' ?>>Valor fixo (R$)</option>
                        </select>
                    </label>
                </p>
                <p>
                    <label>Valor do desconto *<br>
                        <input type="number" name="discount_amount" min="0.01" step="0.01" value="<?= $e($v['discount_amount']) ?>" required>
                    </label>
                </p>
            </div>
        </fieldset>

        <fieldset>
            <legend>Websites e validade</legend>
            <p>
                <label>Websites * <span class="promotion-required">Selecione ao menos um.</span><br>
                    <select name="website_ids[]" multiple required size="<?= min(5, max(2, count($websites))) ?>">
                    <?php foreach ($websites as $w): ?>
                        <option value="<?= (int) $w['website_id'] ?>" <?= in_array((int) $w['website_id'], $selected_websites, true) ? 'selected' : '' ?>><?= $e($w['name']) ?></option>
                    <?php endforeach; ?>
                    </select>
                </label>
            </p>
            <div class="promotion-fields-row">
                <p>
                    <label>Subtotal mínimo (R$)<br>
                        <input type="number" name="minimum_subtotal" min="0" step="0.01" value="<?= $e($v['minimum_subtotal']) ?>">
                    </label>
                </p>
                <p>
                    <label>Subtotal máximo (R$)<br>
                        <input type="number" name="maximum_subtotal" min="0" step="0.01" value="<?= $e($v['maximum_subtotal']) ?>">
                    </label>
                </p>
            </div>
            <div class="promotion-fields-row">
                <p>
                    <label>Início da validade<br>
                        <input type="datetime-local" name="starts_at" value="<?= $e($v['starts_at'] ? date('Y-m-d\TH:i', strtotime($v['starts_at'])) : '') ?>">
                    </label>
                    <small>Deixe vazio para não definir data inicial.</small>
                </p>
                <p>
                    <label>Fim da validade<br>
                        <input type="datetime-local" name="ends_at" value="<?= $e($v['ends_at'] ? date('Y-m-d\TH:i', strtotime($v['ends_at'])) : '') ?>">
                    </label>
                    <small>Deixe vazio para não definir data final.</small>
                </p>
            </div>
        </fieldset>

        <fieldset>
            <legend>Elegibilidade</legend>
            <p>
                <label>IDs dos produtos<br>
                    <input type="text" name="product_ids" value="<?= $e($product_ids) ?>" placeholder="Ex.: 12, 25, 48">
                </label>
                <small>Separe os IDs por vírgula. Vazio significa todos os produtos elegíveis.</small>
            </p>
            <p>
                <label>IDs das categorias<br>
                    <input type="text" name="category_ids" value="<?= $e($category_ids) ?>" placeholder="Ex.: 3, 8">
                </label>
                <small>Separe os IDs por vírgula. Use para restringir a promoção às categorias escolhidas.</small>
            </p>
            <p>
                <label>IDs dos conjuntos de atributos<br>
                    <input type="text" name="attribute_set_ids" value="<?= $e($attribute_set_ids) ?>" placeholder="Ex.: 4, 6">
                </label>
                <small>Separe os IDs por vírgula. Se várias condições forem preenchidas, o produto precisa corresponder às condições configuradas.</small>
            </p>
        </fieldset>

        <fieldset>
            <legend>Prioridade e combinação</legend>
            <p class="promotion-check">
                <label><input type="checkbox" name="is_accumulative" value="1" <?= $v['is_accumulative'] ? 'checked' : '' ?>> Permitir acumular com outras promoções acumulativas</label>
            </p>
            <p>
                <label>Prioridade (menor número é processado primeiro)<br>
                    <input type="number" name="priority" min="0" step="1" value="<?= (int) $v['priority'] ?>">
                </label>
            </p>
            <p class="promotion-check">
                <label><input type="checkbox" name="applies_to_sale_price" value="1" <?= $v['applies_to_sale_price'] ? 'checked' : '' ?>> Aplicar também a produtos com preço promocional próprio</label>
            </p>
        </fieldset>

        <fieldset>
            <legend>Cupons</legend>
            <p>Os códigos só são necessários para promoções por cupom. Códigos já cadastrados são preservados; informe aqui apenas novos códigos, um por linha.</p>
            <p>
                <label>Limite de utilização<br>
                    <select name="coupon_usage_mode">
                        <option value="unlimited" <?= $coupon_usage_mode === 'unlimited' ? 'selected' : '' ?>>Ilimitado</option>
                        <option value="once_total" <?= $coupon_usage_mode === 'once_total' ? 'selected' : '' ?>>Uma única utilização no total</option>
                        <option value="once_per_customer" <?= $coupon_usage_mode === 'once_per_customer' ? 'selected' : '' ?>>Uma utilização por cliente (exige login)</option>
                    </select>
                </label>
            </p>
            <p>
                <label>Novos códigos de cupom<br>
                    <textarea name="coupon_codes" rows="4" maxlength="2000" placeholder="JARDIM10&#10;VERA15"></textarea>
                </label>
                <small>Até 32 caracteres por código; letras, números, hífen e sublinhado.</small>
            </p>
            <?php if ($existing_coupons): ?>
            <div class="promotion-existing-coupons">
                <strong>Cupons cadastrados</strong>
                <p><?= $e(implode(', ', $existing_coupons)) ?></p>
            </div>
            <?php endif; ?>
        </fieldset>

        <div class="promotion-form-actions">
            <button type="submit">Salvar promoção</button>
            <a href="/admin/promotion/index">Cancelar</a>
        </div>
    </form>
</section>
