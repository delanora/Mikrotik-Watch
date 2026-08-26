<?php
declare(strict_types=1);
/**
 * @var array $summary       Resumo de Mikrotiks
 * @var array $hostSummary   Resumo de Hosts
 * @var array $offlineMikrotiks  Mikrotiks offline
 * @var array $downHosts         Hosts down
 */

function timeAgo(?string $datetime): string
{
    if ($datetime === null) {
        return 'desconhecido';
    }

    $now = new \DateTimeImmutable();
    $past = new \DateTimeImmutable($datetime);
    $diff = $now->diff($past);

    if ($diff->d > 0) {
        return "{$diff->d}d {$diff->h}h {$diff->i}m";
    }
    if ($diff->h > 0) {
        return "{$diff->h}h {$diff->i}m";
    }
    if ($diff->i > 0) {
        return "{$diff->i}m";
    }
    return "{$diff->s}s";
}
?>

<div class="page-header">
    <h1>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        Dashboard
    </h1>
    <button type="button" id="btn-sync" class="btn btn-primary" onclick="runCollect()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
        Sincronizar
    </button>
</div>

<!-- ─── Resumo ────────────────────────────────────────────────────────────── -->

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon icon-accent">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/></svg>
        </div>
        <div class="stat-info">
            <h3><?= (int) $summary['total_mikrotiks'] ?></h3>
            <p>Total Mikrotiks</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color: var(--success); border-color: var(--success-border); background: var(--success-bg);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div class="stat-info">
            <h3 style="color: var(--success);"><?= (int) $summary['online_mikrotiks'] ?></h3>
            <p>Mikrotiks Online</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color: var(--danger); border-color: var(--danger-border); background: var(--danger-bg);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div class="stat-info">
            <h3 style="color: var(--danger);"><?= (int) $summary['offline_mikrotiks'] ?></h3>
            <p>Mikrotiks Offline</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
        </div>
        <div class="stat-info">
            <h3><?= (int) $hostSummary['total_hosts'] ?></h3>
            <p>Total Hosts</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color: var(--success); border-color: var(--success-border); background: var(--success-bg);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div class="stat-info">
            <h3 style="color: var(--success);"><?= (int) $hostSummary['up_hosts'] ?></h3>
            <p>Hosts Up</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color: var(--danger); border-color: var(--danger-border); background: var(--danger-bg);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div class="stat-info">
            <h3 style="color: var(--danger);"><?= (int) $hostSummary['down_hosts'] ?></h3>
            <p>Hosts Down</p>
        </div>
    </div>
</div>

<!-- ─── Mikrotiks Offline ─────────────────────────────────────────────────── -->

<div class="card">
    <div class="card-header">
        <h2>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            Mikrotiks Offline
            <?php if (count($offlineMikrotiks) > 0): ?>
                <span class="badge badge-danger" style="margin-left: 4px;"><?= count($offlineMikrotiks) ?></span>
            <?php endif; ?>
        </h2>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($offlineMikrotiks)): ?>
            <div class="alert alert-success" style="margin: 24px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <div>Nenhum Mikrotik offline no momento. Todos os equipamentos estão operacionais.</div>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Equipamento</th>
                        <th>Host</th>
                        <th>Status</th>
                        <th>Offline há</th>
                        <th style="text-align: right;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($offlineMikrotiks as $m): ?>
                        <tr>
                            <td>
                                <span class="text-muted"><?= htmlspecialchars($m['client_name'] ?? '—') ?></span>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($m['name']) ?></strong>
                            </td>
                            <td>
                                <code><?= htmlspecialchars($m['host']) ?></code>
                            </td>
                            <td>
                                <span class="badge badge-danger">offline</span>
                            </td>
                            <td>
                                <strong><?= timeAgo($m['status_since']) ?></strong>
                            </td>
                            <td style="text-align: right;">
                                <a href="/mikrotiks/<?= htmlspecialchars($m['id']) ?>" class="btn btn-ghost" title="Ver detalhes">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- ─── Hosts Offline (Netwatch) ──────────────────────────────────────────── -->

<div class="card">
    <div class="card-header">
        <h2>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
            Hosts Offline (Netwatch)
            <?php if (count($downHosts) > 0): ?>
                <span class="badge badge-danger" style="margin-left: 4px;"><?= count($downHosts) ?></span>
            <?php endif; ?>
        </h2>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($downHosts)): ?>
            <div class="alert alert-success" style="margin: 24px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <div>Nenhum host offline no momento. Todos os hosts estão respondendo.</div>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Equipamento</th>
                        <th>Host</th>
                        <th>Status</th>
                        <th>Offline há</th>
                        <th style="text-align: right;">Comentário</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($downHosts as $h): ?>
                        <tr>
                            <td>
                                <span class="text-muted"><?= htmlspecialchars($h['client_name'] ?? '—') ?></span>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($h['mikrotik_name'] ?? '—') ?></strong>
                            </td>
                            <td>
                                <code><?= htmlspecialchars($h['host_address']) ?></code>
                            </td>
                            <td>
                                <span class="badge badge-danger">down</span>
                            </td>
                            <td>
                                <strong><?= timeAgo($h['status_since']) ?></strong>
                            </td>
                            <td style="text-align: right;">
                                <?php if (!empty($h['comment'])): ?>
                                    <span style="background: var(--warning-bg); color: var(--warning); padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">
                                        <?= htmlspecialchars($h['comment']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<style>
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>

<script>
function runCollect() {
    const btn = document.getElementById('btn-sync');
    const origText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="animation: spin 1s linear infinite;"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg> Sincronizando...';

    fetch('/api/collect', { method: 'POST' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                location.reload();
            } else {
                alert('Erro na coleta:\n' + data.message);
                btn.disabled = false;
                btn.innerHTML = origText;
            }
        })
        .catch(function(err) {
            alert('Erro de conexão: ' + err.message);
            btn.disabled = false;
            btn.innerHTML = origText;
        });
}
</script>
