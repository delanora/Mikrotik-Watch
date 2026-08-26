<?php
declare(strict_types=1);
/**
 * @var array|null $mikrotik  Dados do equipamento (null = criação)
 * @var array      $clients   Lista de clientes ativos
 * @var array      $errors    Lista de erros de validação
 */
$isEdit = !empty($mikrotik['id']);
$formAction = $isEdit ? "/mikrotiks/{$mikrotik['id']}" : '/mikrotiks/store';
?>

<div class="breadcrumb">
    <a href="/mikrotiks">Equipamentos</a>
    <span class="breadcrumb-sep">›</span>
    <span class="breadcrumb-current"><?= $isEdit ? 'Editar' : 'Novo Equipamento' ?></span>
</div>

<div class="page-header">
    <h1>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
        <?= $isEdit ? 'Editar Equipamento' : 'Novo Equipamento' ?>
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

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <div><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            <?= $isEdit ? 'Dados do Equipamento' : 'Informações do Equipamento' ?>
        </h2>
    </div>
    <div class="card-body">
        <form method="POST" action="<?= htmlspecialchars($formAction) ?>" id="mikrotik-form">
            <?= \App\Middleware\CsrfMiddleware::field() ?>

            <!-- Cliente -->
            <div class="form-group">
                <label for="client_id">Cliente *</label>
                <select id="client_id" name="client_id" required>
                    <option value="">Selecione um cliente...</option>
                    <?php foreach ($clients as $client): ?>
                        <option value="<?= htmlspecialchars($client['id']) ?>"
                            <?= (($mikrotik['client_id'] ?? '') === $client['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($client['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Nome -->
            <div class="form-group">
                <label for="name">Nome do Equipamento *</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    value="<?= htmlspecialchars($mikrotik['name'] ?? '') ?>"
                    placeholder="Ex: Router-Principal"
                    required
                    maxlength="150"
                >
            </div>

            <!-- Host -->
            <div class="form-group">
                <label for="host">Host (IP ou DDNS) *</label>
                <input
                    type="text"
                    id="host"
                    name="host"
                    value="<?= htmlspecialchars($mikrotik['host'] ?? '') ?>"
                    placeholder="Ex: 192.168.88.1 ou router.dominio.com"
                    required
                >
            </div>

            <!-- Porta + SSL -->
            <div style="display: grid; grid-template-columns: 120px 1fr; gap: 16px;">
                <div class="form-group">
                    <label for="port">Porta</label>
                    <input
                        type="number"
                        id="port"
                        name="port"
                        value="<?= htmlspecialchars((string) ($mikrotik['port'] ?? 443)) ?>"
                        min="1"
                        max="65535"
                    >
                </div>
                <div class="form-group" style="display: flex; align-items: flex-end; padding-bottom: 4px;">
                    <label class="checkbox-label">
                        <input
                            type="checkbox"
                            id="use_ssl"
                            name="use_ssl"
                            value="1"
                            <?= !empty($mikrotik['use_ssl']) || !$isEdit ? 'checked' : '' ?>
                        >
                        Usar HTTPS (SSL)
                    </label>
                </div>
            </div>

            <!-- Usuário -->
            <div class="form-group">
                <label for="username">Usuário *</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    value="<?= htmlspecialchars($mikrotik['username'] ?? 'admin') ?>"
                    placeholder="admin"
                    required
                >
            </div>

            <!-- Senha -->
            <div class="form-group">
                <label for="password">Senha <?= $isEdit ? '(deixe vazio para manter)' : '*' ?></label>
                <div style="display: flex; gap: 8px;">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="<?= $isEdit ? '•••••••• (manter atual)' : '••••••••' ?>"
                        <?= $isEdit ? '' : 'required' ?>
                        autocomplete="new-password"
                        style="flex: 1;"
                    >
                    <button type="button" id="btn-test-connection" class="btn btn-secondary" style="white-space: nowrap;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                        Testar Conexão
                    </button>
                </div>
                <?php if ($isEdit): ?>
                    <small>A senha nunca é exibida. Preencha apenas para alterar.</small>
                <?php endif; ?>
            </div>

            <!-- Resultado do teste de conexão -->
            <div id="test-result" style="display: none; margin-bottom: 20px;"></div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <?= $isEdit ? 'Salvar Alterações' : 'Criar Equipamento' ?>
                </button>
                <a href="/mikrotiks" class="btn btn-secondary">Cancelar</a>
            </div>

        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const btnTest = document.getElementById('btn-test-connection');
    const resultDiv = document.getElementById('test-result');

    btnTest.addEventListener('click', function() {
        const host = document.getElementById('host').value.trim();
        const port = document.getElementById('port').value;
        const useSsl = document.getElementById('use_ssl').checked;
        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('password').value;

        if (!host || !username || !password) {
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = '<div class="alert alert-warning"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg><div>Preencha host, usuário e senha antes de testar.</div></div>';
            return;
        }

        btnTest.disabled = true;
        btnTest.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="animation: spin 1s linear infinite;"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg> Testando...';
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div class="alert alert-info"><div>Testando conexão...</div></div>';

        fetch('/mikrotiks/test', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ host, port, use_ssl: useSsl, username, password })
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                var resource = data.resource || {};
                var info = '';
                if (resource.version) info += 'RouterOS: ' + resource.version;
                if (resource['board-name']) info += (info ? ' | ' : '') + 'Board: ' + resource['board-name'];
                if (resource.uptime) info += (info ? ' | ' : '') + 'Uptime: ' + resource.uptime;

                resultDiv.innerHTML = '<div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><div><strong>' + data.message + '</strong>' + (info ? '<br><small>' + info + '</small>' : '') + '</div></div>';
            } else {
                resultDiv.innerHTML = '<div class="alert alert-error"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><div>' + data.message + '</div></div>';
            }
        })
        .catch(function(err) {
            resultDiv.innerHTML = '<div class="alert alert-error"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><div>Erro de conexão: ' + err.message + '</div></div>';
        })
        .finally(function() {
            btnTest.disabled = false;
            btnTest.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg> Testar Conexão';
        });
    });
});
</script>

<style>
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>
