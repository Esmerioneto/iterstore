<?php $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); $money=static fn($v)=>'R$ '.number_format((float)$v,2,',','.'); ?>
<section class="promotion-uses"><h2>Usos de promoção<?= $promotion ? ': '.$e($promotion['name']) : '' ?></h2>
<?php if (!$promotion): ?><p>Promoção não encontrada.</p><?php else: ?>
<p><a href="/admin/promotion/edit?id=<?= (int)$promotion['promotion_id'] ?>">← Voltar para a promoção</a></p>
<table><thead><tr><th>Data</th><th>Cupom</th><th>Pedido</th><th>Cliente</th><th>Valor de desconto</th><th>Uso</th><th>Situação do pedido</th></tr></thead><tbody>
<?php if (!$uses): ?><tr><td colspan="7" class="admin-empty">Nenhum uso registrado.</td></tr><?php endif; ?>
<?php foreach($uses as $u): ?><tr><td><?= $e(date('d/m/Y H:i',strtotime((string)$u['created_at']))) ?></td><td><?= $e($u['code']) ?></td><td><a href="/admin/order/view?id=<?= (int)$u['order_id'] ?>"><?= $e($u['increment_id']) ?></a></td><td><?= $e(trim($u['firstname'].' '.$u['lastname'])) ?><br><small><?= $e($u['email']) ?><?= $u['customer_id'] ? ' · cliente #'.(int)$u['customer_id'] : ' · visitante' ?></small></td><td><?= $money($u['discount_amount']) ?></td><td><?= $u['status']==='used'?'Consumido':($u['status']==='released'?'Liberado':'Aplicado') ?></td><td><span class="admin-status admin-status-<?= $e($u['order_status']) ?>"><?= $e(\Iter\Sales\Model\OrderService::STATUS_LABELS[$u['order_status']] ?? $u['order_status']) ?></span></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?></section>
