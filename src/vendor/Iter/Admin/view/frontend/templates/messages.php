<?php
$labels = ['success' => 'Sucesso', 'error' => 'Erro', 'warning' => 'Aviso'];
?>
<div class="admin-messages">
<?php foreach ($messages as $message): ?>
    <div class="message message-<?= htmlspecialchars($message['type']) ?>" data-label="<?= htmlspecialchars($labels[$message['type']] ?? $message['type']) ?>"><?= htmlspecialchars($message['text']) ?></div>
<?php endforeach; ?>
</div>
