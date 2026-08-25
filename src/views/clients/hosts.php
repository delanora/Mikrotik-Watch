<?php
declare(strict_types=1);
/**
 * @var array $client        Cliente selecionado
 * @var array $clients       Todos os clientes (para seletor)
 * @var array $mikrotiks     Mikrotiks do cliente
 * @var array $hosts         Hosts Netwatch do cliente
 */

function timeAgo(?string $datetime): string
{
    if ($datetime === null) {
        return 'nunca';
    }

    $now = new \DateTimeImmutable();
    $past = new \DateTimeImmutable($datetime);
    $diff = $now->diff($past);

    if ($diff->d > 0) {
        return "{$diff->d}d {$diff->h}h";
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

<div class="breadcrumb">
    <a href="/clients">Clientes</a>
    <span class="breadcrumb-sep">›</span>
    <a href="/clients/<?= htmlspecialchars($client['id']) ?>/edit"><?= htmlspecialchars($client['name']) ?></a>
    <span class="breadcrumb-sep">›</span>
    <span class="breadcrumb-current">Hosts Netwatch</span>
</div>

<div class="page-header">
    <h1>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
        Hosts Netwatch
    </h1>
</div>

<!-- ─── Seletor de Cliente ────────────────────────────────────────────────── -->

<?php if (!empty($clients)): ?>
<div class="card" style="margin-bottom: 0;">
    <div class="card-body" style="padding: 16px 24px;">
        <form method="GET" style="display: flex; align-items: center; gap: 12px;">
            <label style="font-size: 13px; font-weight: 600; color: var(--text-secondary); white-space: nowrap;">Cliente:</label>
            <select name="client_id" onchange="if(this.value) window.location.href='/clients/'+this.value+'/hosts'" style="padding: 8px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; background: var(--bg-secondary); color: var(--text-primary); min-width: 200px;">
                <?php foreach ($clients as $c): ?>
                    <option value="<?= htmlspecialchars($c['id']) ?>" <?= ($c['id'] === $client['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ─── Lista de Hosts ────────────────────────────────────────────────────── -->

<?php if (empty($mikrotiks)): ?>
    <div class="card">
        <div class="card-body">
            <div class="alert alert-warning">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <div>Este cliente não possui equipamentos Mikrotiks cadastrados. Cadastre um equipamento primeiro para monitorar hosts via Netwatch.</div>
            </div>
        </div>
    </div>
<?php elseif (empty($hosts)): ?>
    <div class="card">
        <div class="card-body">
            <div class="alert alert-warning">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <div>Nenhum host Netwatch configurado nos equipamentos deste cliente. Os hosts serão sincronizados automaticamente pelo cron de coleta.</div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header">
            <h2>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                Hosts de <?= htmlspecialchars($client['name']) ?>
                <span class="badge badge-secondary" style="margin-left: 4px;"><?= count($hosts) ?></span>
            </h2>
        </div>
        <div class="card-body" style="padding: 0;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Equipamento</th>
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
                                <a href="/mikrotiks/<?= htmlspecialchars($h['mikrotik_id']) ?>">
                                    <?= htmlspecialchars($h['mikrotik_name']) ?>
                                </a>
                            </td>
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
        </div>
    </div>
<?php endif; ?>
