<?php
declare(strict_types=1);
/** @var array $mikrotiks */

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

<div class="page-header">
    <h1>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
        Hosts
    </h1>
</div>

<?php if (empty($mikrotiks)): ?>
    <div class="card">
        <div class="card-body text-center text-muted" style="padding: 60px 24px;">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin: 0 auto 16px; display: block; opacity: 0.3;"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
            <p style="font-size: 15px; margin-bottom: 8px;">Nenhum equipamento cadastrado</p>
            <p style="margin-bottom: 20px;">Cadastre um equipamento Mikrotik para monitorar hosts.</p>
            <a href="/mikrotiks/create" class="btn btn-primary">Adicionar Equipamento</a>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header">
            <h2>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/></svg>
                Equipamentos
                <span class="badge badge-secondary" style="margin-left: 4px;"><?= count($mikrotiks) ?></span>
            </h2>
        </div>
        <div class="card-body" style="padding: 0;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Equipamento</th>
                        <th>Cliente</th>
                        <th>Status</th>
                        <th>Hosts</th>
                        <th style="text-align: right;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mikrotiks as $m): ?>
                        <tr>
                            <td>
                                <a href="/hosts/<?= htmlspecialchars($m['id']) ?>" style="font-weight: 600;">
                                    <?= htmlspecialchars($m['name']) ?>
                                </a>
                                <br>
                                <code style="font-size: 11px;"><?= htmlspecialchars($m['host']) ?></code>
                            </td>
                            <td>
                                <span class="text-muted"><?= htmlspecialchars($m['client_name'] ?? '—') ?></span>
                            </td>
                            <td>
                                <?php
                                $status = $m['current_status'] ?? 'unknown';
                                $statusClass = match ($status) {
                                    'online'  => 'badge-success',
                                    'offline' => 'badge-danger',
                                    default   => 'badge-secondary',
                                };
                                ?>
                                <span class="badge <?= $statusClass ?>"><?= $status ?></span>
                            </td>
                            <td>
                                <?php if ((int) $m['total_hosts'] > 0): ?>
                                    <span class="badge badge-secondary"><?= (int) $m['total_hosts'] ?></span>
                                    <?php if ((int) $m['down_hosts'] > 0): ?>
                                        <span class="badge badge-danger"><?= (int) $m['down_hosts'] ?> down</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">0</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <a href="/hosts/<?= htmlspecialchars($m['id']) ?>" class="btn btn-ghost" title="Ver Hosts">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
