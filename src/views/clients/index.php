<?php
declare(strict_types=1);
/** @var array $clients */
?>

<div class="page-header">
    <h1>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Clientes
    </h1>
    <a href="/clients/create" class="btn btn-primary">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Novo Cliente
    </a>
</div>

<?php if (empty($clients)): ?>
    <div class="card">
        <div class="card-body text-center text-muted" style="padding: 60px 24px;">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin: 0 auto 16px; display: block; opacity: 0.3;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <p style="font-size: 15px; margin-bottom: 8px;">Nenhum cliente cadastrado</p>
            <p style="margin-bottom: 20px;">Cadastre o primeiro cliente para começar a monitorar equipamentos.</p>
            <a href="/clients/create" class="btn btn-primary">Criar Primeiro Cliente</a>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header">
            <h2>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                Todos os Clientes
                <span class="badge badge-secondary" style="margin-left: 4px;"><?= count($clients) ?></span>
            </h2>
        </div>
        <div class="card-body" style="padding: 0;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Mikrotiks</th>
                        <th>Status</th>
                        <th>Telegram</th>
                        <th style="text-align: right;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $client): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($client['name']) ?></strong>
                            </td>
                            <td>
                                <span class="badge badge-secondary"><?= (int) $client['mikrotik_count'] ?></span>
                            </td>
                            <td>
                                <?php if ((int) $client['mikrotik_count'] === 0): ?>
                                    <span class="badge badge-secondary">Sem equipamentos</span>
                                <?php elseif ((int) $client['offline_count'] > 0): ?>
                                    <span class="badge badge-danger"><?= (int) $client['online_count'] ?> online / <?= (int) $client['offline_count'] ?> offline</span>
                                <?php else: ?>
                                    <span class="badge badge-success"><?= (int) $client['online_count'] ?> online</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($client['telegram_group_id'])): ?>
                                    <code><?= htmlspecialchars((string) $client['telegram_group_id']) ?></code>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions" style="justify-content: flex-end;">
                                    <?php if ((int) ($client['mikrotik_count'] ?? 0) > 0): ?>
                                    <a href="/clients/<?= htmlspecialchars($client['id']) ?>/hosts" class="btn btn-ghost" title="Ver Hosts">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                                    </a>
                                    <?php endif; ?>
                                    <a href="/clients/<?= htmlspecialchars($client['id']) ?>/edit" class="btn btn-ghost" title="Editar">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </a>
                                    <form method="POST" action="/clients/<?= htmlspecialchars($client['id']) ?>/delete" style="display: inline;"
                                          onsubmit="return confirm('⚠️ ATENÇÃO: Excluir este cliente irá remover PERMANENTAMENTE todos os Mikrotiks e todo o histórico de dados vinculados (health_log, netwatch_events, mikrotik_events).\n\nEsta ação não pode ser desfeita.\n\nDeseja continuar?');">
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
<?php endif; ?>
