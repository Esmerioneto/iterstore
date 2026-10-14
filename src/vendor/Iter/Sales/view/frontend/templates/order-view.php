<?php
use Iter\Core\FormKey;
use Iter\Inventory\Model\StockService;
use Iter\Sales\Model\OrderService;

$e = static fn ($v) => htmlspecialchars((string) $v);
$money = static fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
$id = (int) $order['order_id'];
$address = static function (?array $a) use ($e): string {
    if ($a === null) {
        return '';
    }
    $lines = [trim($a['street'] . ', ' . $a['number'] . ($a['complement'] ? ' - ' . $a['complement'] : ''), ', '), $a['district'],
        trim($a['city'] . ' / ' . $a['region'], ' /'), $a['postcode'] ? 'CEP ' . substr($a['postcode'], 0, 5) . '-' . substr($a['postcode'], 5) : '',
        $a['recipient'] ? 'Destinatário: ' . $a['recipient'] : '', $a['telephone'] ? 'Telefone: ' . $a['telephone'] : ''];
    foreach (array_diff_key($a, array_flip(['postcode', 'street', 'number', 'complement', 'district', 'city', 'region', 'recipient', 'telephone'])) as $code => $value) {
        $lines[] = $code . ': ' . (is_array($value) ? implode(', ', $value) : $value);
    }
    return implode('<br>', array_map($e, array_filter($lines, 'strlen')));
};
$form = static fn (string $action, string $inner, string $confirm = '') => '<form method="post" action="/admin/order/' . $action . '"' . ($confirm ? ' data-confirm="' . htmlspecialchars($confirm) . '"' : '') . '>'
    . FormKey::field() . '<input type="hidden" name="order_id" value="' . $id . '">' . $inner . '</form>';
// Mercado Pago's payment_method.id → the brand's name; the newest charge with a card says which one
// (logos in /assets/admin/card-brands; a brand without one shows just the name)
$brands = ['master' => 'Mastercard', 'amex' => 'American Express', 'hipercard' => 'Hipercard', 'elo' => 'Elo', 'visa' => 'Visa', 'debvisa' => 'Visa', 'debmaster' => 'Mastercard', 'debelo' => 'Elo'];
$brand = current(array_filter(array_column($charges, 'card_brand'))) ?: null;
$brandName = $brand ? ($brands[$brand] ?? ucfirst($brand)) : '';
$brandIcon = ['master' => 'master', 'debmaster' => 'master', 'visa' => 'visa', 'debvisa' => 'visa', 'elo' => 'elo', 'debelo' => 'elo', 'amex' => 'amex'][(string) $brand] ?? null;
?>
<section class="mail-view sales-order-view">
    <h2>Pedido <?= $e($order['increment_id']) ?> · <span class="admin-status admin-status-<?= $e($order['status']) ?>"><?= $e($order['status_label']) ?></span></h2>
    <table class="sales-order-info">
        <tr><th>Loja</th><td><?= $e($order['website_name'] ?? '—') ?></td></tr>
        <tr><th>Canal</th><td><?= $e($order['sales_channel']) ?></td></tr>
<?php if ($order['status'] === 'canceled'): ?>
        <tr><th>Motivo do cancelamento</th><td><?= $e(OrderService::CANCEL_REASONS[$order['cancel_reason'] ?? ''] ?? 'Não informado') ?></td></tr>
<?php endif; ?>
        <tr><th>Data</th><td><?= $e(substr((string) $order['created_at'], 0, 16)) ?></td></tr>
        <tr><th>Cliente</th><td><?= $e($order['firstname'] . ' ' . $order['lastname']) ?> · <?= $e($order['email']) ?> · CPF/CNPJ <?= $e($order['taxvat']) ?>
            <?= $order['customer_id'] === null ? ' (visitante)' : ' · <a href="/admin/customer/edit?id=' . (int) $order['customer_id'] . '">ver cliente</a>' ?></td></tr>
        <tr><th>Frete</th><td><?= $e($order['shipping_label'] ?? '—') ?> · <?= $money($order['shipping_amount']) ?><?= $order['shipping_days'] !== null ? ' · ' . (int) $order['shipping_days'] . ' dia(s) útil(eis)' : '' ?></td></tr>
        <tr><th>Pagamento</th><td><?= in_array($order['payment_method'] ?? '', ['pix', 'mercadopago_pix'], true) ? '<img class="sales-card-brand" src="/assets/admin/card-brands/pix.svg" alt="" width="38" height="24"> ' : '' ?><?= $e($order['payment_label'] ?? '—') ?><?= $brand ? ' · ' . ($brandIcon ? '<img class="sales-card-brand" src="/assets/admin/card-brands/' . $brandIcon . '.svg" alt="" width="38" height="24"> ' : '') . '<strong>' . $e($brandName) . '</strong>' : '' ?><?php if ($order['payment_instructions']): ?><br><small style="white-space: pre-line;"><?= $e($order['payment_instructions']) ?></small><?php endif; ?>
<?php foreach ($charges as $c): ?>
            <br><small>Mercado Pago #<?= $e($c['external_id']) ?> · <?= $e($c['status_label']) ?><?= $c['card_brand'] ? ' · ' . $e($brands[$c['card_brand']] ?? ucfirst($c['card_brand'])) : '' ?><?= $c['card_number'] ? ' ' . $e($c['card_number']) : '' ?><?= $c['installments'] > 1 ? ' · ' . (int) $c['installments'] . 'x' : '' ?> · <?= $money($c['amount']) ?></small>
<?php endforeach; ?></td></tr>
<?php if ($order['shipment']): ?>
        <tr><th>Entrega</th><td>Saiu de <?= $e($order['shipment']['source_name']) ?> em <?= $e(substr((string) $order['shipment']['created_at'], 0, 16)) ?>
            <?= $order['shipment']['carrier'] ? ' · ' . $e($order['shipment']['carrier']) : '' ?><?= $order['shipment']['tracking_code'] ? ' · rastreio ' . $e($order['shipment']['tracking_code']) : '' ?></td></tr>
<?php endif; ?>
        <tr><th>Endereço de entrega</th><td><?= $address($order['shipping_address']) ?></td></tr>
        <tr><th>Endereço de cobrança</th><td><?= $address($order['billing_address']) ?></td></tr>
    </table>

    <h3>Itens</h3>
    <table class="sales-order-items">
        <thead><tr><th>SKU</th><th>Produto</th><th>Qtd.</th><th>Preço</th><th>Total</th></tr></thead>
        <tbody>
<?php foreach ($order['items'] as $item): ?>
            <tr>
                <td><?= $e($item['sku']) ?></td>
                <td><?= $e($item['name']) ?><?= $item['options'] ? '<br><small>' . $e($item['options']) . '</small>' : '' ?></td>
                <td><?= $e(str_replace('.', ',', StockService::format((string) $item['quantity']))) ?></td>
                <td><?= $money($item['price']) ?><?= $item['regular_price'] ? '<br><small class="sales-promo-price">Promoção · de <s>' . $money($item['regular_price']) . '</s></small>' : '' ?></td>
                <td><?= $money(max(0.0, (float) $item['row_total'] - (float) ($item['discount_amount'] ?? 0))) ?><?= !empty($item['discount_amount']) ? '<br><small class="sales-promo-price">Desconto: − ' . $money($item['discount_amount']) . '</small>' : '' ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <?php if (!empty($order['discounts'])): ?><h3>Promoções aplicadas</h3><ul><?php foreach ($order['discounts'] as $discount): ?><li><?= $e($discount['promotion_name']) ?><?= $discount['coupon_code'] ? ' · cupom ' . $e($discount['coupon_code']) : '' ?>: − <?= $money($discount['discount_amount']) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <p class="sales-order-totals">Subtotal <?= $money($order['subtotal']) ?><?= !empty($order['discount_amount']) ? ' · Descontos − ' . $money($order['discount_amount']) : '' ?> · Frete <?= $money($order['shipping_amount']) ?> · <strong>Total <?= $money($order['grand_total']) ?></strong></p>

<?php if ($actions): ?>
    <h3>Ações</h3>
<?php endif; ?>
<?php if (in_array('confirm', $actions, true)): ?>
    <?= $form('confirm', '<label>Comentário (ex.: comprovante recebido pelo WhatsApp) <input type="text" name="comment" maxlength="1000"></label> <button type="submit">Confirmar pagamento</button>') ?>
<?php endif; ?>
<?php if (in_array('ship', $actions, true)): ?>
    <?php if (!$sources): ?>
    <p>Nenhuma origem ativa neste website: cadastre uma em Estoque → Origens para registrar a entrega.</p>
    <?php else: ?>
    <?php
    $options = '';
    foreach ($sources as $source) {
        $options .= '<option value="' . $source['source_id'] . '">' . $e($source['name'] . ($source['covers'] ? '' : ' (não tem tudo)')) . '</option>';
    }
    ?>
    <?= $form('ship', '<label>Origem <select name="source_id">' . $options . '</select></label> <label>Transportadora <input type="text" name="carrier" maxlength="255"></label>'
        . ' <label>Código de rastreio <input type="text" name="tracking_code" maxlength="255"></label> <button type="submit">Registrar entrega</button>') ?>
    <?php endif; ?>
<?php endif; ?>
<?php if (in_array('complete', $actions, true)): ?>
    <?= $form('complete', '<button type="submit">Concluir (entregue)</button>') ?>
<?php endif; ?>
<?php if (in_array('cancel', $actions, true)): ?>
    <?php
    $reasons = '<option value="">Escolha...</option>';
    foreach (OrderService::CANCEL_REASONS as $code => $label) {
        $reasons .= '<option value="' . $code . '">' . $e($label) . '</option>';
    }
    $returned = OrderService::returnedItems($order);
    // paid through Mercado Pago: refunded there, unless the admin switches it off and says how the money went back
    $mpPaid = $order['status'] === 'processing' ? array_sum(array_column(array_filter($charges, static fn ($c) => $c['status'] === 'approved'), 'amount')) : 0;
    $refundField = '<label class="sales-order-cancel-refund">Como o valor foi devolvido <textarea name="refund_note" rows="2" maxlength="1000"' . ($mpPaid > 0 ? '' : ' required')
        . ' placeholder="Ex.: Pix para a chave do cliente em 12/10"></textarea><small>' . ($mpPaid > 0 ? 'Só se não estornar pelo Mercado Pago (ex.: já estornou no painel).' : 'O pedido já foi pago: isto vai para o histórico e para o e-mail do cliente.') . '</small></label>';
    ?>
    <div class="sales-order-cancel">
    <?= $form('cancel', '<label>Motivo <select name="cancel_reason" required>' . $reasons . '</select></label>'
        . ' <label>Complemento <input type="text" name="comment" maxlength="1000" placeholder="Obrigatório com &quot;Outro&quot;"></label>'
        . ($mpPaid > 0 ? ' <label class="mobile-switch"><input type="hidden" name="refund" value="0"><input type="checkbox" class="admin-switch" name="refund" value="1" checked> Estornar ' . $money($mpPaid) . ' no Mercado Pago</label>' : '')
        . ($order['status'] === 'processing' ? ' ' . $refundField : '')
        . ' <label class="mobile-switch"><input type="hidden" name="notify" value="0"><input type="checkbox" class="admin-switch" name="notify" value="1" checked> Avisar o cliente por e-mail</label>'
        . ' <button type="submit">Cancelar pedido</button>',
        'Cancelar este pedido?' . ($returned !== '' ? ' Voltam ao estoque: ' . $returned . '.' : '') . ($mpPaid > 0 ? ' Com a chave ligada, ' . $money($mpPaid) . ' são estornados no Mercado Pago.' : '')) ?>
    </div>
<?php endif; ?>

    <h3>Histórico</h3>
    <?= $form('comment', '<label>Comentário <textarea name="comment" rows="2" maxlength="5000" required></textarea></label> <label><input type="checkbox" name="notify" value="1"> Avisar o cliente por e-mail</label> <button type="submit">Comentar</button>') ?>
    <table>
        <thead><tr><th>Data</th><th>Situação</th><th>Comentário</th><th>Cliente avisado</th><th>Por</th></tr></thead>
        <tbody>
<?php foreach ($order['history'] as $row): ?>
            <tr>
                <td><?= $e(substr((string) $row['created_at'], 0, 16)) ?></td>
                <td><span class="admin-status admin-status-<?= $e($row['status']) ?>"><?= $e(OrderService::STATUS_LABELS[$row['status']] ?? $row['status']) ?></span></td>
                <td style="white-space: pre-line;"><?= $e($row['comment']) ?></td>
                <td><?= $row['is_customer_notified'] ? 'Sim' : 'Não' ?></td>
                <td><?= $e($row['admin_username'] ?? '—') ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <p class="sales-order-back"><a href="/admin/order/index">← Voltar para pedidos</a></p>
</section>
