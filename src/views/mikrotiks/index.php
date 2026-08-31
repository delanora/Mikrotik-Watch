<?php
declare(strict_types=1);
/**
 * @var array $mikrotiks     Lista de Mikrotiks (paginaada)
 * @var array $clients       Lista de clientes (para filtro)
 * @var array $summary       Resumo de contadores
 * @var string $filterClientId  Filtro selecionado
 * @var int $page            Página atual
 * @var int $totalPages      Total de páginas
 * @var int $totalRows       Total de registros
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

function cpuBadge(?int $cpu): string
{
    if ($cpu === null) {
        return '<span class="text-muted">N/A</span>';
    }
    if ($cpu >= 95) {
        return '<span class="badge badge-danger">' . $cpu . '%</span>';
    }
    if ($cpu >= 80) {
        return '<span class="badge badge-warning">' . $cpu . '%</span>';
    }
    return '<span>' . $cpu . '%</span>';
}

function memBadge(?int $free, ?int $total): string
{
    if ($free === null || $total === null || $total === 0) {
        return '<span class="text-muted">N/A</span>';
    }
    $used = $total - $free;
    $pct = (int) round(($used / $total) * 100);
    if ($pct >= 95) {
        return '<span class="badge badge-danger">' . $pct . '%</span>';
    }
    if ($pct >= 85) {
        return '<span class="badge badge-warning">' . $pct . '%</span>';
    }
    return '<span>' . $pct . '%</span>';
}

function tempDisplay(?string $temp): string
{
    if ($temp === null || $temp === '') {
        return '<span class="text-muted">N/A</span>';
    }
    $val = (float) $temp;
    $display = rtrim(rtrim(number_format($val, 2, '.', ''), '0'), '.');
    if ($val >= 80) {
        return '<span class="badge badge-danger">' . $display . '°C</span>';
    }
    if ($val >= 65) {
        return '<span class="badge badge-warning">' . $display . '°C</span>';
    }
    return '<span>' . $display . '°C</span>';
}

// Parâmetros de paginação para URLs
$baseParams = '';
if ($filterClientId !== '') {
    $baseParams = '&client_id=' . urlencode($filterClientId);
}
?>

<div class="page-header">
    <h1>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
        Equipamentos
    </h1>
    <a href="/mikrotiks/create" class="btn btn-primary">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Novo Equipamento
    </a>
</div>

<!-- ─── Resumo ────────────────────────────────────────────────────────────── -->

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon icon-accent">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/></svg>
        </div>
        <div class="stat-info">
            <h3><?= (int) $summary['total'] ?></h3>
            <p>Total</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color: var(--success); border-color: var(--success-border); background: var(--success-bg);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div class="stat-info">
            <h3 style="color: var(--success);"><?= (int) $summary['online'] ?></h3>
            <p>Online</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color: var(--danger); border-color: var(--danger-border); background: var(--danger-bg);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div class="stat-info">
            <h3 style="color: var(--danger);"><?= (int) $summary['offline'] ?></h3>
            <p>Offline</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color: var(--warning); border-color: var(--warning-border); background: var(--warning-bg);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <div class="stat-info">
            <h3 style="color: var(--warning);"><?= (int) $summary['warning'] ?></h3>
            <p>Em Atenção</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
        </div>
        <div class="stat-info">
            <h3><?= (int) $summary['unknown'] ?></h3>
            <p>Desconhecido</p>
        </div>
    </div>
</div>

<!-- ─── Filtro ────────────────────────────────────────────────────────────── -->

<?php if (!empty($clients)): ?>
<div class="card" style="margin-bottom: 0;">
    <div class="card-body" style="padding: 16px 24px;">
        <form method="GET" action="/mikrotiks" style="display: flex; align-items: center; gap: 12px;">
            <label style="font-size: 13px; font-weight: 600; color: var(--text-secondary); white-space: nowrap;">Filtrar por cliente:</label>
            <select name="client_id" onchange="this.form.submit()" style="padding: 8px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; background: var(--bg-secondary); color: var(--text-primary); min-width: 200px;">
                <option value="">Todos os clientes</option>
                <?php foreach ($clients as $client): ?>
                    <option value="<?= htmlspecialchars($client['id']) ?>" <?= ($filterClientId === $client['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($client['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($filterClientId !== ''): ?>
                <a href="/mikrotiks" class="btn btn-secondary" style="font-size: 12px; height: 32px;">
                    Limpar filtro
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ─── Tabela ────────────────────────────────────────────────────────────── -->

<?php if (empty($mikrotiks)): ?>
    <div class="card">
        <div class="card-body text-center text-muted" style="padding: 60px 24px;">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin: 0 auto 16px; display: block; opacity: 0.3;"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
            <?php if ($filterClientId !== ''): ?>
                <p style="font-size: 15px; margin-bottom: 8px;">Nenhum equipamento para este cliente</p>
                <p style="margin-bottom: 20px;">Este cliente não possui equipamentos cadastrados.</p>
                <a href="/mikrotiks" class="btn btn-secondary">Ver todos</a>
            <?php else: ?>
                <p style="font-size: 15px; margin-bottom: 8px;">Nenhum equipamento cadastrado</p>
                <p style="margin-bottom: 20px;">Adicione um equipamento Mikrotik para começar a monitorar.</p>
                <a href="/mikrotiks/create" class="btn btn-primary">Adicionar Primeiro Equipamento</a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header">
            <h2>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/></svg>
                Equipamentos
                <span class="badge badge-secondary" style="margin-left: 4px;"><?= $totalRows ?></span>
            </h2>
        </div>
        <div class="card-body" style="padding: 0;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Equipamento</th>
                        <th>Status</th>
                        <th>CPU</th>
                        <th>Memória</th>
                        <th>Temp</th>
                        <th>Última coleta</th>
                        <th style="text-align: right;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mikrotiks as $m): ?>
                        <tr>
                            <td>
                                <span class="text-muted"><?= htmlspecialchars($m['client_name'] ?? '—') ?></span>
                            </td>
                            <td>
                                <a href="/mikrotiks/<?= htmlspecialchars($m['id']) ?>" style="font-weight: 600;">
                                    <?= htmlspecialchars($m['name']) ?>
                                </a>
                                <?php if (($m['device_type'] ?? 'mikrotik') === 'ping'): ?>
                                    <span class="badge badge-secondary" style="font-size: 10px; padding: 1px 6px; margin-left: 4px;">Ping</span>
                                <?php endif; ?>
                                <br>
                                <code style="font-size: 11px;"><?= htmlspecialchars($m['host']) ?></code>
                                <?php if (($m['device_type'] ?? 'mikrotik') === 'mikrotik'): ?>
                                    <?php if ((int) ($m['port'] ?? 443) !== 443): ?>
                                        <span class="text-muted" style="font-size: 11px;">:<?= (int) $m['port'] ?></span>
                                    <?php endif; ?>
                                    <?php if (!(bool) ($m['use_ssl'] ?? true)): ?>
                                        <span class="badge badge-warning" style="font-size: 10px; padding: 1px 6px; margin-left: 4px;">HTTP</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $status = $m['current_status'] ?? 'unknown';
                                $statusClass = match ($status) {
                                    'online'  => 'badge-success',
                                    'offline' => 'badge-danger',
                                    'warning' => 'badge-warning',
                                    default   => 'badge-secondary',
                                };
                                ?>
                                <span class="badge <?= $statusClass ?>"><?= $status ?></span>
                            </td>
                            <td>
                                <?php if (($m['device_type'] ?? 'mikrotik') === 'mikrotik'): ?>
                                    <?= cpuBadge($m['last_cpu_load'] !== null ? (int) $m['last_cpu_load'] : null) ?>
                                <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (($m['device_type'] ?? 'mikrotik') === 'mikrotik'): ?>
                                    <?= memBadge(
                                        $m['last_memory_free'] !== null ? (int) $m['last_memory_free'] : null,
                                        $m['last_memory_total'] !== null ? (int) $m['last_memory_total'] : null
                                    ) ?>
                                <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (($m['device_type'] ?? 'mikrotik') === 'mikrotik'): ?>
                                    <?= tempDisplay($m['last_temperature']) ?>
                                <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="text-muted" style="font-size: 12px;">
                                    <?= timeAgo($m['last_checked_at']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions" style="justify-content: flex-end;">
                                    <a href="/mikrotiks/<?= htmlspecialchars($m['id']) ?>" class="btn btn-ghost" title="Detalhes">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </a>
                                    <a href="/mikrotiks/<?= htmlspecialchars($m['id']) ?>/edit" class="btn btn-ghost" title="Editar">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </a>
                                    <form method="POST" action="/mikrotiks/<?= htmlspecialchars($m['id']) ?>/delete" style="display: inline;"
                                          onsubmit="return confirm('Excluir este equipamento? Os dados de histórico serão mantidos mas o equipamento não será mais monitorado.');">
                                        <?= \App\Middleware\CsrfMiddleware::field() ?>
                                        <button type="submit" class="btn btn-ghost btn-danger" title="Excluir">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <span class="pagination-info">Página <?= $page ?> de <?= $totalPages ?> (<?= $totalRows ?> registros)</span>

        <a href="/mikrotiks?page=<?= max(1, $page - 1) . $baseParams ?>" class="pagination-btn" <?= $page <= 1 ? 'disabled' : '' ?>>
            ‹ Anterior
        </a>

        <?php
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        if ($endPage - $startPage < 4) {
            if ($startPage === 1) { $endPage = min($totalPages, $startPage + 4); }
            else { $startPage = max(1, $endPage - 4); }
        }
        ?>

        <?php if ($startPage > 1): ?>
            <a href="/mikrotiks?page=1<?= $baseParams ?>" class="pagination-btn">1</a>
            <?php if ($startPage > 2): ?><span class="pagination-ellipsis">…</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
            <a href="/mikrotiks?page=<?= $i . $baseParams ?>" class="pagination-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>

        <?php if ($endPage < $totalPages): ?>
            <?php if ($endPage < $totalPages - 1): ?><span class="pagination-ellipsis">…</span><?php endif; ?>
            <a href="/mikrotiks?page=<?= $totalPages . $baseParams ?>" class="pagination-btn"><?= $totalPages ?></a>
        <?php endif; ?>

        <a href="/mikrotiks?page=<?= min($totalPages, $page + 1) . $baseParams ?>" class="pagination-btn" <?= $page >= $totalPages ? 'disabled' : '' ?>>
            Próxima ›
        </a>
    </div>
    <?php endif; ?>
<?php endif; ?>
