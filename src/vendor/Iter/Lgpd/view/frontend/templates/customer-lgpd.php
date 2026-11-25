<?php
if ($customerId === null) return;

$e = static fn ($v) => htmlspecialchars((string) $v);
?>
<section class="mail-list lgpd-customer">
    <h2>LGPD</h2>
<?php if ($anonymizedAt): ?>
    <p>Dados excluídos a pedido do titular em <?= $e(date('d/m/Y H:i', strtotime((string) $anonymizedAt))) ?>. Os pedidos ficam pelo prazo fiscal.</p>
<?php else: ?>
    <p>Pedido do cliente sobre os dados dele (cópia, correção, exclusão)? Registre para cumprir o prazo e guardar a prova:
        <a href="/admin/lgpdrequest/new?customer_id=<?= (int) $customerId ?>&amp;type=export">cópia dos dados</a> ·
        <a href="/admin/lgpdrequest/new?customer_id=<?= (int) $customerId ?>&amp;type=erase">exclusão</a> ·
        <a href="/admin/lgpdrequest/new?customer_id=<?= (int) $customerId ?>&amp;type=correction">correção</a></p>
<?php endif; ?>
<?php if ($requests): ?>
    <table>
        <thead><tr><th>Protocolo</th><th>Tipo</th><th>Recebida em</th><th>Situação</th></tr></thead>
        <tbody>
    <?php foreach ($requests as $r): ?>
            <tr>
                <td><a href="/admin/lgpdrequest/view?id=<?= (int) $r['request_id'] ?>"><code><?= $e(\Iter\Lgpd\Model\RequestService::protocol((int) $r['request_id'])) ?></code></a></td>
                <td><?= $e($types[$r['type']] ?? $r['type']) ?></td>
                <td><?= $e(date('d/m/Y H:i', strtotime((string) $r['created_at']))) ?></td>
                <td><?= $e($statuses[$r['status']] ?? $r['status']) ?></td>
            </tr>
    <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</section>
