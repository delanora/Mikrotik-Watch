<?php
declare(strict_types=1);
/**
 * @var array $mikrotik
 * @var array $hosts
 */

function timeAgo(?string $datetime): string
{
    if ($datetime === null) {
        return 'nunca';
    }
    $now = new \DateTimeImmutable();
    $past = new \DateTimeImmutable($datetime);
    $diff = $now->diff($past);
    if ($diff->d > 0) return "{$diff->d}d {$diff->h}h";
    if ($diff->h > 0) return "{$diff->h}h {$diff->i}m";
    if ($diff->i > 0) return "{$diff->i}m";
    return "{$diff->s}s";
}
?>

<div class="breadcrumb">
    <a href="/hosts">Hosts</a>
    <span class="breadcrumb-sep">›</span>
    <span class="breadcrumb-current"><?= htmlspecialchars($mikrotik['name']) ?></span>
</div>

<div class="page-header">
    <h1>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
        <?= htmlspecialchars($mikrotik['name']) ?>
    </h1>
    <div style="display: flex; gap: 8px; align-items: center;">
        <?php
        $status = $mikrotik['current_status'] ?? 'unknown';
        $statusClass = match ($status) {
            'online'  => 'badge-success',
            'offline' => 'badge-danger',
            default   => 'badge-secondary',
        };
        ?>
        <span class="badge <?= $statusClass ?>"><?= $status ?></span>
        <span class="text-muted">•</span>
        <span class="text-muted"><?= htmlspecialchars($mikrotik['client_name'] ?? '') ?></span>
        <span class="text-muted">•</span>
        <code><?= htmlspecialchars($mikrotik['host']) ?></code>
    </div>
</div>

<!-- ─── Resumo ────────────────────────────────────────────────────────────── -->

<?php
$total = count($hosts);
$up = 0;
$down = 0;
$unknown = 0;
foreach ($hosts as $h) {
    match ($h['current_status']) {
        'up' => $up++,
        'down' => $down++,
        default => $unknown++,
    };
}
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon icon-accent">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
        </div>
        <div class="stat-info">
            <h3><?= $total ?></h3>
            <p>Total Hosts</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color: var(--success); border-color: var(--success-border); background: var(--success-bg);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div class="stat-info">
            <h3 style="color: var(--success);"><?= $up ?></h3>
            <p>Up</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color: var(--danger); border-color: var(--danger-border); background: var(--danger-bg);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div class="stat-info">
            <h3 style="color: var(--danger);"><?= $down ?></h3>
            <p>Down</p>
        </div>
    </div>
    <?php if ($unknown > 0): ?>
    <div class="stat-card">
        <div class="stat-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
        </div>
        <div class="stat-info">
            <h3><?= $unknown ?></h3>
            <p>Desconhecido</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ─── Tabela de Hosts ───────────────────────────────────────────────────── -->

<div class="card">
    <div class="card-header">
        <h2>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
            Hosts Netwatch
            <?php if ($total > 0): ?>
                <span class="badge badge-secondary" style="margin-left: 4px;"><?= $total ?></span>
            <?php endif; ?>
        </h2>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($hosts)): ?>
            <div class="alert alert-warning" style="margin: 24px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <div>Nenhum host Netwatch configurado neste equipamento. Os hosts serão sincronizados automaticamente pelo cron.</div>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Endereço</th>
                        <th>Comentário</th>
                        <th>Status</th>
                        <th>RTT</th>
                        <th>Status há</th>
                        <th>Última verificação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($hosts as $h): ?>
                        <tr>
                            <td>
                                <code><?= htmlspecialchars($h['host_address']) ?></code>
                            </td>
                            <td>
                                <?php if (!empty($h['comment'])): ?>
                                    <?= htmlspecialchars($h['comment']) ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $status = $h['current_status'] ?? 'unknown';
                                $statusClass = match ($status) {
                                    'up'      => 'badge-success',
                                    'down'    => 'badge-danger',
                                    default   => 'badge-secondary',
                                };
                                ?>
                                <span class="badge <?= $statusClass ?>"><?= $status ?></span>
                            </td>
                            <td>
                                <?php if ($h['current_status'] === 'up' && $h['last_rtt_ms'] !== null): ?>
                                    <span><?= (int) $h['last_rtt_ms'] ?>ms</span>
                                <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= timeAgo($h['status_since']) ?></strong>
                            </td>
                            <td>
                                <span class="text-muted" style="font-size: 12px;">
                                    <?= timeAgo($h['last_checked_at']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
                <div class="pagination" style="display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 20px;">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>" class="btn btn-secondary" style="padding: 6px 12px;">
                            ← Anterior
                        </a>
                    <?php endif; ?>

                    <span style="color: var(--text-muted); font-size: 13px;">
                        Página <?= $page ?> de <?= $totalPages ?>
                        <span style="margin-left: 8px;">
                            (<?= $totalHosts ?> hosts)
                        </span>
                    </span>

                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>" class="btn btn-secondary" style="padding: 6px 12px;">
                            Próxima →
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>
