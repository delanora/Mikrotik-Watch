<?php
declare(strict_types=1);
/**
 * @var array $users Lista de usuários
 */
?>

<div class="breadcrumb">
    <a href="/dashboard">Dashboard</a>
    <span class="breadcrumb-sep">›</span>
    <span class="breadcrumb-current">Usuários</span>
</div>

<div class="page-header">
    <h1>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Usuários
    </h1>
    <a href="/users/create" class="btn btn-primary">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Novo Usuário
    </a>
</div>

<div class="card">
    <div class="card-header">
        <h2>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            Todos os Usuários
            <span class="badge badge-secondary" style="margin-left: 4px;"><?= count($users) ?></span>
        </h2>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($users)): ?>
            <div class="alert alert-warning" style="margin: 24px;">
                Nenhum usuário cadastrado.
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>E-mail</th>
                        <th>Papel</th>
                        <th>Criado em</th>
                        <th style="text-align: right;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($u['name']) ?></strong>
                            </td>
                            <td>
                                <span class="text-muted"><?= htmlspecialchars($u['email']) ?></span>
                            </td>
                            <td>
                                <?php if ($u['role'] === 'admin'): ?>
                                    <span class="badge badge-primary">admin</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">viewer</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="text-muted" style="font-size: 12px;">
                                    <?= date('d/m/Y H:i', strtotime($u['created_at'])) ?>
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <?php if ($u['id'] !== ($_SESSION['user_id'] ?? '')): ?>
                                    <form method="POST" action="/users/<?= htmlspecialchars($u['id']) ?>/delete" style="display: inline;"
                                          onsubmit="return confirm('Excluir este usuário? Esta ação não pode ser desfeita.');">
                                        <?= \App\Middleware\CsrfMiddleware::field() ?>
                                        <button type="submit" class="btn btn-ghost btn-danger" title="Excluir">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
