<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Cormorant+Garamond:wght@400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/sweetalert2/sweetalert2.min.css">
    <link rel="stylesheet" href="/assets/admin/theme.css?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/admin/theme.css') ?>">
    <script src="/assets/vendor/sweetalert2/sweetalert2.min.js" defer></script>
    <script src="/assets/admin/admin.js?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/admin/admin.js') ?>" defer></script>
</head>
<body>
<div class="admin-shell">
<header class="admin-header">
<?php if ($username): ?>
    <?php /* narrow screens: opens the menu as a drawer (admin.js); hidden on wide ones */ ?>
    <button type="button" class="admin-menu-toggle" aria-controls="admin-menu" aria-expanded="false">Menu</button>
<?php endif; ?>
    <h1><?= htmlspecialchars($title) ?></h1>
<?php if (!$username): ?>
    <img class="admin-logo" src="/imagem/logo.png" alt="Iter">
<?php endif; ?>
<?php if ($username): ?>
    <nav class="admin-user-nav">
        <span><?= htmlspecialchars($username) ?></span>
        <a href="/admin/auth/password">Alterar senha</a>
        <a href="/admin/auth/logout">Sair</a>
    </nav>
<?php endif; ?>
</header>
