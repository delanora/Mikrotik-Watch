<?php
declare(strict_types=1);
/** @var array $mikrotiks */
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

<?php if (empty($mikrotiks)): ?>
    <div class="card">
        <div class="card-body text-center text-muted" style="padding: 60px 24px;">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin: 0 auto 16px; display: block; opacity: 0.3;"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
            <p style="font-size: 15px; margin-bottom: 8px;">Nenhum equipamento cadastrado</p>
            <p style="margin-bottom: 20px;">Adicione um equipamento Mikrotik para começar a monitorar.</p>
            <a href="/mikrotiks/create" class="btn btn-primary">Adicionar Primeiro Equipamento</a>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header">
            <h2>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/></svg>
                Todos os Equipamentos
                <span class="badge badge-secondary" style="margin-left: 4px;"><?= count($mikrotiks) ?></span>
            </h2>
        </div>
        <div class="card-body" style="padding: 0;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Cliente</th>
                        <th>Host</th>
                        <th>Status</th>
                        <th>RouterOS</th>
                        <th style="text-align: right;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mikrotiks as $m): ?>
                        <tr>
                            <td>
                                <a href="/mikrotiks/<?= htmlspecialchars($m['id']) ?>" style="font-weight: 600;">
                                    <?= htmlspecialchars($m['name']) ?>
                                </a>
                            </td>
                            <td>
                                <span class="text-muted"><?= htmlspecialchars($m['client_name'] ?? '—') ?></span>
                            </td>
                            <td>
                                <code><?= htmlspecialchars($m['host']) ?></code>
                                <?php if ((int) $m['port'] !== 443): ?>
                                    <span class="text-muted">:<?= (int) $m['port'] ?></span>
                                <?php endif; ?>
                                <?php if (!(bool) $m['use_ssl']): ?>
                                    <span class="badge badge-warning" style="margin-left: 4px;">HTTP</span>
                                <?php endif; ?>
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
                                <?php if (!empty($m['routeros_version'])): ?>
                                    <span class="text-muted"><?= htmlspecialchars($m['routeros_version']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
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
<?php endif; ?>
