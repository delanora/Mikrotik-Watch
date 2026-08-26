<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Mikrotik Watch' ?> - Mikrotik Watch</title>

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">

    <!-- CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">

    <!-- Chart.js via CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-brand">
            <a href="/">Mikrotik Watch</a>
        </div>
        <div class="navbar-menu">
            <a href="/dashboard">Dashboard</a>
            <a href="/clients">Clientes</a>
            <a href="/mikrotiks">Equipamentos</a>
            <a href="/settings">Configurações</a>
        </div>
        <div class="navbar-user">
            <span><?= htmlspecialchars($_SESSION['user_name'] ?? 'Visitante') ?></span>
            <a href="/logout">Sair</a>
        </div>
    </nav>

    <main class="container">
