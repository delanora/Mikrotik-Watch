<?php
declare(strict_types=1);
/**
 * @var array|null $user  Dados do usuário (null = criação)
 * @var array      $errors Lista de erros de validação
 */
?>

<div class="breadcrumb">
    <a href="/users">Usuários</a>
    <span class="breadcrumb-sep">›</span>
    <span class="breadcrumb-current">Novo Usuário</span>
</div>

<div class="page-header">
    <h1>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
        Novo Usuário
    </h1>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <div>
            <?php foreach ($errors as $error): ?>
                <div><?= htmlspecialchars($error) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Dados do Usuário
        </h2>
    </div>
    <div class="card-body">
        <form method="POST" action="/users/store">
            <?= \App\Middleware\CsrfMiddleware::field() ?>

            <!-- Nome -->
            <div class="form-group">
                <label for="name">Nome *</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    value="<?= htmlspecialchars($user['name'] ?? '') ?>"
                    placeholder="Nome completo"
                    required
                >
            </div>

            <!-- E-mail -->
            <div class="form-group">
                <label for="email">E-mail *</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= htmlspecialchars($user['email'] ?? '') ?>"
                    placeholder="usuario@exemplo.com"
                    required
                >
            </div>

            <!-- Senha -->
            <div class="form-group">
                <label for="password">Senha *</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Mínimo 6 caracteres"
                    required
                    minlength="6"
                >
                <small>Mínimo de 6 caracteres.</small>
            </div>

            <!-- Papel -->
            <div class="form-group">
                <label for="role">Papel</label>
                <select id="role" name="role">
                    <option value="viewer" <?= ($user['role'] ?? '') === 'viewer' ? 'selected' : '' ?>>Viewer (somente leitura)</option>
                    <option value="admin" <?= ($user['role'] ?? 'admin') === 'admin' ? 'selected' : '' ?>>Admin (acesso total)</option>
                </select>
                <small>
                    <strong>Admin:</strong> Acesso total (criar, editar, excluir).<br>
                    <strong>Viewer:</strong> Somente visualizar (dashboard, listagens).
                </small>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Criar Usuário
                </button>
                <a href="/users" class="btn btn-secondary">Cancelar</a>
            </div>

        </form>
    </div>
</div>
