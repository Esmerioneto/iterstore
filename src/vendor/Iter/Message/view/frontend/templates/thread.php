<?php
use Iter\Sales\Model\OrderService;

$e = static fn ($v) => htmlspecialchars((string) $v);
$id = (int) $customer['customer_id'];
$name = $customer['customer_type'] === 'pj' && $customer['company_name'] ? $customer['company_name'] : trim($customer['firstname'] . ' ' . $customer['lastname']);
?>
<section class="message-thread">
    <h2>Conversa com <?= $e($name) ?></h2>
    <div class="message-thread-layout">
        <div>
            <!-- admin.js reloads this block every 30 s (data-refresh) -->
            <div class="message-bubbles" id="message-bubbles" data-refresh="30">
<?php if ($older): ?>
                <p class="message-older"><a href="/admin/message/view?customer_id=<?= $id ?>&amp;before=<?= (int) $older ?>">Ver mensagens anteriores</a></p>
<?php endif; ?>
<?php if ($before): ?>
                <p class="message-older"><a href="/admin/message/view?customer_id=<?= $id ?>">Ver as mais recentes</a></p>
<?php endif; ?>
<?php if (!$messages): ?>
                <p class="admin-list-summary">Nenhuma mensagem ainda. Escreva abaixo para iniciar a conversa.</p>
<?php endif; ?>
<?php foreach ($messages as $m): ?>
                <div class="message-bubble message-from-<?= $m['sender'] === 'admin' ? 'admin' : 'customer' ?>">
                    <p><?= $e($m['body']) ?></p>
                    <small><?= $m['sender'] === 'admin' ? $e($m['admin_name'] ?? 'Loja') . ' · ' : '' ?><?= $e(date('d/m/Y H:i', strtotime((string) $m['created_at']))) ?><?= $m['sender'] === 'admin' ? ($m['read_at'] ? ' · lida' : ' · não lida') : '' ?></small>
                </div>
<?php endforeach; ?>
            </div>
            <form method="post" action="/admin/message/send" class="message-reply">
                <?= \Iter\Core\FormKey::field() ?>
                <input type="hidden" name="customer_id" value="<?= $id ?>">
                <textarea name="body" rows="3" maxlength="2000" required placeholder="Responder..."><?= $e($draft) ?></textarea>
                <button type="submit">Enviar</button>
            </form>
        </div>
        <aside class="message-customer">
            <h3>Cliente</h3>
            <p><strong><?= $e($name) ?></strong><br>
                <?= $e($customer['email']) ?><br>
<?php if ($taxvat !== ''): ?>
                <?= $customer['customer_type'] === 'pj' ? 'CNPJ' : 'CPF' ?> <?= $e($taxvat) ?><br>
<?php endif; ?>
<?php if ($phone): ?>
                <?= $e($phone) ?><br>
<?php endif; ?>
                Website: <?= $e($customer['website_name']) ?></p>
            <h3>Últimos pedidos (<?= (int) $orders['total'] ?>)</h3>
<?php if (!$orders['items']): ?>
            <p class="admin-list-summary">Nenhum pedido.</p>
<?php endif; ?>
<?php foreach ($orders['items'] as $o): ?>
            <p><a href="/admin/order/view?id=<?= (int) $o['order_id'] ?>"><?= $e($o['increment_id']) ?></a> · <?= $e(date('d/m/Y', strtotime((string) $o['created_at']))) ?><br>
                <small>R$ <?= number_format((float) $o['grand_total'], 2, ',', '.') ?> · <?= $e(OrderService::STATUS_LABELS[$o['status']] ?? $o['status']) ?></small></p>
<?php endforeach; ?>
            <p><a href="/admin/customer/edit?id=<?= $id ?>">Ver cadastro</a></p>
            <p><a href="/admin/message/index">← Voltar para as mensagens</a></p>
        </aside>
    </div>
</section>
