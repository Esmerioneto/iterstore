<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Cormorant+Garamond:wght@400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/admin/theme.css">
</head>
<body>
<div class="admin-shell">
<header class="admin-header">
    <h1><?= htmlspecialchars($title) ?></h1>
<?php if (!$username): ?>
    <img class="admin-logo" src="/imagem/logo.jpeg" alt="Iter">
<?php endif; ?>
<?php if ($username): ?>
    <nav class="admin-user-nav">
        <span><?= htmlspecialchars($username) ?></span>
        <a href="/admin/auth/password">Alterar senha</a>
        <a href="/admin/auth/logout">Sair</a>
    </nav>
<?php endif; ?>
</header>
