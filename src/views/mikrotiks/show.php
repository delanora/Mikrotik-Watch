<?php
declare(strict_types=1);
/**
 * @var array $mikrotik
 * @var array $healthLogs
 */
?>

<div class="breadcrumb">
    <a href="/mikrotiks">Equipamentos</a>
    <span class="breadcrumb-sep">›</span>
    <span class="breadcrumb-current"><?= htmlspecialchars($mikrotik['name']) ?></span>
</div>

<div class="page-header">
    <h1>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
        <?= htmlspecialchars($mikrotik['name']) ?>
    </h1>
    <div style="display: flex; gap: 8px;">
        <a href="/mikrotiks/<?= htmlspecialchars($mikrotik['id']) ?>/edit" class="btn btn-secondary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Editar
        </a>
    </div>
</div>

<!-- Status Card -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon icon-accent">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div class="stat-info">
            <h3><?= htmlspecialchars($mikrotik['current_status'] ?? 'unknown') ?></h3>
            <p>Status Atual</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/></svg>
        </div>
        <div class="stat-info">
            <h3><?= htmlspecialchars($mikrotik['routeros_version'] ?? '—') ?></h3>
            <p>RouterOS</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/></svg>
        </div>
        <div class="stat-info">
            <h3><?= htmlspecialchars($mikrotik['board_name'] ?? '—') ?></h3>
            <p>Board</p>
        </div>
    </div>
</div>

<!-- Detalhes do Equipamento -->
<div class="card">
    <div class="card-header">
        <h2>Informações</h2>
    </div>
    <div class="card-body">
        <div class="peer-details">
            <div class="peer-detail">
                <span class="peer-detail-label">Cliente</span>
                <span class="peer-detail-value"><?= htmlspecialchars($mikrotik['client_name'] ?? '—') ?></span>
            </div>
            <div class="peer-detail">
                <span class="peer-detail-label">Host</span>
                <span class="peer-detail-mono"><?= htmlspecialchars($mikrotik['host']) ?></span>
            </div>
            <div class="peer-detail">
                <span class="peer-detail-label">Porta</span>
                <span class="peer-detail-value"><?= (int) $mikrotik['port'] ?></span>
            </div>
            <div class="peer-detail">
                <span class="peer-detail-label">HTTPS</span>
                <span class="peer-detail-value"><?= !empty($mikrotik['use_ssl']) ? 'Sim' : 'Não' ?></span>
            </div>
            <div class="peer-detail">
                <span class="peer-detail-label">Usuário</span>
                <span class="peer-detail-mono"><?= htmlspecialchars($mikrotik['username']) ?></span>
            </div>
            <div class="peer-detail">
                <span class="peer-detail-label">Cadastrado em</span>
                <span class="peer-detail-value"><?= date('d/m/Y H:i', strtotime($mikrotik['created_at'])) ?></span>
            </div>
        </div>
    </div>
</div>
