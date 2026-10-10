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
?>
<section class="mail-view sales-order-view">
    <h2>Pedido <?= $e($order['increment_id']) ?> · <?= $e($order['status_label']) ?></h2>
    <table class="sales-order-info">
        <tr><th>Loja</th><td><?= $e($order['website_name'] ?? '—') ?></td></tr>
        <tr><th>Data</th><td><?= $e(substr((string) $order['created_at'], 0, 16)) ?></td></tr>
        <tr><th>Cliente</th><td><?= $e($order['firstname'] . ' ' . $order['lastname']) ?> · <?= $e($order['email']) ?> · CPF/CNPJ <?= $e($order['taxvat']) ?>
            <?= $order['customer_id'] === null ? ' (visitante)' : ' · <a href="/admin/customer/edit?id=' . (int) $order['customer_id'] . '">ver cliente</a>' ?></td></tr>
        <tr><th>Frete</th><td><?= $e($order['shipping_label'] ?? '—') ?><?= $order['shipping_days'] !== null ? ' · ' . (int) $order['shipping_days'] . ' dia(s) útil(eis)' : '' ?></td></tr>
        <tr><th>Pagamento</th><td><?= $e($order['payment_label'] ?? '—') ?><?php if ($order['payment_instructions']): ?><br><small style="white-space: pre-line;"><?= $e($order['payment_instructions']) ?></small><?php endif; ?>
<?php foreach ($charges as $c): ?>
            <br><small>Mercado Pago #<?= $e($c['external_id']) ?> · <?= $e($c['status_label']) ?><?= $c['card_brand'] ? ' · ' . $e(ucfirst($c['card_brand'])) : '' ?><?= $c['card_number'] ? ' ' . $e($c['card_number']) : '' ?><?= $c['installments'] > 1 ? ' · ' . (int) $c['installments'] . 'x' : '' ?> · <?= $money($c['amount']) ?></small>
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
                <td><?= $money($item['price']) ?></td>
                <td><?= $money($item['row_total']) ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <p class="sales-order-totals">Subtotal <?= $money($order['subtotal']) ?> · Frete <?= $money($order['shipping_amount']) ?> · <strong>Total <?= $money($order['grand_total']) ?></strong></p>

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
    <?= $form('cancel', '<label>Motivo <input type="text" name="comment" maxlength="1000" required></label> <button type="submit">Cancelar pedido</button>', 'Cancelar este pedido? O estoque reservado volta para a venda e o cliente é avisado.') ?>
<?php endif; ?>

    <h3>Histórico</h3>
    <?= $form('comment', '<label>Comentário <textarea name="comment" rows="2" maxlength="5000" required></textarea></label> <label><input type="checkbox" name="notify" value="1"> Avisar o cliente por e-mail</label> <button type="submit">Comentar</button>') ?>
    <table>
        <thead><tr><th>Data</th><th>Situação</th><th>Comentário</th><th>Cliente avisado</th><th>Por</th></tr></thead>
        <tbody>
<?php foreach ($order['history'] as $row): ?>
            <tr>
                <td><?= $e(substr((string) $row['created_at'], 0, 16)) ?></td>
                <td><?= $e(OrderService::STATUS_LABELS[$row['status']] ?? $row['status']) ?></td>
                <td style="white-space: pre-line;"><?= $e($row['comment']) ?></td>
                <td><?= $row['is_customer_notified'] ? 'Sim' : 'Não' ?></td>
                <td><?= $e($row['admin_username'] ?? '—') ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <p class="sales-order-back"><a href="/admin/order/index">← Voltar para pedidos</a></p>
</section>
