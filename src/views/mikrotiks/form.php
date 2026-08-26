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
                <label for="client_search">Cliente *</label>
                <div class="autocomplete-wrapper">
                    <input type="text" id="client_search" placeholder="Buscar cliente..."
                        value="<?= htmlspecialchars($mikrotik['client_name'] ?? '') ?>" autocomplete="off" required>
                    <input type="hidden" id="client_id" name="client_id" value="<?= htmlspecialchars($mikrotik['client_id'] ?? '') ?>" required>
                    <div class="autocomplete-list" id="client-list"></div>
                </div>
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

            <!-- Tipo de dispositivo -->
            <div class="form-group">
                <label>Este equipamento é Mikrotik?</label>
                <div class="device-type-selector">
                    <div class="device-type-card <?= ($mikrotik['device_type'] ?? 'mikrotik') === 'mikrotik' ? 'active' : '' ?>" onclick="document.getElementById('device_type_mikrotik').checked=true; toggleDeviceType();">
                        <div class="device-type-card-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
                        </div>
                        <div class="device-type-card-text">
                            <div class="device-type-card-title">Mikrotik RouterOS</div>
                            <div class="device-type-card-desc">Coleta métricas via API REST (CPU, memória, temperatura)</div>
                        </div>
                    </div>
                    <div class="device-type-card <?= ($mikrotik['device_type'] ?? '') === 'ping' ? 'active' : '' ?>" onclick="document.getElementById('device_type_ping').checked=true; toggleDeviceType();">
                        <div class="device-type-card-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                        </div>
                        <div class="device-type-card-text">
                            <div class="device-type-card-title">Monitoramento por Ping</div>
                            <div class="device-type-card-desc">Verificação de disponibilidade via ICMP (ping)</div>
                        </div>
                    </div>
                    <input type="radio" name="device_type" id="device_type_mikrotik" value="mikrotik"
                        <?= ($mikrotik['device_type'] ?? 'mikrotik') === 'mikrotik' ? 'checked' : '' ?>
                        onchange="toggleDeviceType()" style="display: none;">
                    <input type="radio" name="device_type" id="device_type_ping" value="ping"
                        <?= ($mikrotik['device_type'] ?? '') === 'ping' ? 'checked' : '' ?>
                        onchange="toggleDeviceType()" style="display: none;">
                </div>
            </div>

            <!-- Campos específicos Mikrotik -->
            <div id="mikrotik-fields" style="<?= ($mikrotik['device_type'] ?? 'mikrotik') === 'ping' ? 'display: none;' : '' ?>">

            <!-- Porta + SSL -->
            <div class="form-row-2">
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
                <div class="form-group form-group-checkbox">
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

            </div><!-- /mikrotik-fields -->

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
var clientsData = <?= json_encode(array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name']], $clients), JSON_UNESCAPED_UNICODE) ?>;

function toggleDeviceType() {
    var isMikrotik = document.querySelector('input[name="device_type"]:checked').value === 'mikrotik';
    var fields = document.getElementById('mikrotik-fields');
    var port = document.getElementById('port');
    var username = document.getElementById('username');
    var password = document.getElementById('password');
    var cards = document.querySelectorAll('.device-type-card');

    fields.style.display = isMikrotik ? '' : 'none';

    cards.forEach(function(card) { card.classList.remove('active'); });
    if (isMikrotik) {
        cards[0].classList.add('active');
    } else {
        cards[1].classList.add('active');
    }

    if (!isMikrotik) {
        port.removeAttribute('required');
        username.removeAttribute('required');
        password.removeAttribute('required');
    } else {
        port.setAttribute('required', 'required');
        username.setAttribute('required', 'required');
        password.setAttribute('required', 'required');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    toggleDeviceType();

    // ─── Client Autocomplete ───
    const searchInput = document.getElementById('client_search');
    const hiddenInput = document.getElementById('client_id');
    const listEl = document.getElementById('client-list');
    let selectedId = hiddenInput.value;

    function renderList(query) {
        var q = (query || '').toLowerCase();
        var filtered = q ? clientsData.filter(function(c) { return c.name.toLowerCase().indexOf(q) !== -1; }) : clientsData;
        if (filtered.length === 0) {
            listEl.innerHTML = '<div class="autocomplete-empty">Nenhum cliente encontrado</div>';
        } else {
            listEl.innerHTML = filtered.map(function(c) {
                return '<div class="autocomplete-item' + (c.id === selectedId ? ' selected' : '') + '" data-id="' + c.id + '" data-name="' + c.name.replace(/"/g, '&quot;') + '">' + c.name + '</div>';
            }).join('');
        }
        listEl.style.display = 'block';
    }

    searchInput.addEventListener('input', function() {
        hiddenInput.value = '';
        selectedId = '';
        searchInput.removeAttribute('readonly');
        renderList(this.value);
    });

    searchInput.addEventListener('focus', function() {
        renderList(this.value);
    });

    listEl.addEventListener('click', function(e) {
        var item = e.target.closest('.autocomplete-item');
        if (!item) return;
        searchInput.value = item.dataset.name;
        hiddenInput.value = item.dataset.id;
        selectedId = item.dataset.id;
        listEl.style.display = 'none';
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.autocomplete-wrapper')) {
            listEl.style.display = 'none';
        }
    });

    // If editing, show selected client name
    if (selectedId) {
        var match = clientsData.find(function(c) { return c.id === selectedId; });
        if (match) searchInput.value = match.name;
    }

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

/* Device Type Selector — Segmented Cards */
.device-type-selector {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-top: 8px;
}

.device-type-card {
    display: flex;
    align-items: center;
    gap: 14px;
    text-align: left;
    padding: 14px 18px;
    background: var(--bg-secondary);
    border: 2px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
}

.device-type-card:hover {
    border-color: var(--text-muted);
    background: var(--bg-hover);
}

/* Selected state */
.device-type-card.active {
    border-color: var(--accent);
    background: var(--accent-bg);
}

.device-type-card.active .device-type-card-icon {
    background: var(--accent-bg);
    border-color: var(--accent-border);
    color: var(--accent);
}

/* Checkmark on selected */
.device-type-card.active::after {
    content: '';
    position: absolute;
    top: 50%;
    right: 14px;
    transform: translateY(-50%);
    width: 18px;
    height: 18px;
    background: var(--accent);
    border-radius: 50%;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='20 6 9 17 4 12'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: center;
    background-size: 10px;
}

.device-type-card-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    color: var(--text-muted);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: all 0.2s;
}

.device-type-card-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.device-type-card-title {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-primary);
}

.device-type-card-desc {
    font-size: 12px;
    color: var(--text-muted);
    line-height: 1.3;
}

/* Client Autocomplete */
.autocomplete-wrapper {
    position: relative;
}

.autocomplete-list {
    display: none;
    position: absolute;
    top: 100%;
    left: 0; right: 0;
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    border-radius: 0 0 var(--radius-sm) var(--radius-sm);
    max-height: 200px;
    overflow-y: auto;
    z-index: 50;
    box-shadow: var(--shadow-lg);
}

.autocomplete-item {
    padding: 10px 14px;
    font-size: 13px;
    color: var(--text-primary);
    cursor: pointer;
    transition: background 0.1s;
}

.autocomplete-item:hover,
.autocomplete-item.selected {
    background: var(--accent-bg);
    color: var(--accent);
}

.autocomplete-empty {
    padding: 10px 14px;
    font-size: 13px;
    color: var(--text-muted);
}

/* Form Row 2 columns */
.form-row-2 {
    display: grid;
    grid-template-columns: 120px 1fr;
    gap: 20px;
    align-items: end;
}

.form-group-checkbox {
    display: flex;
    align-items: center;
    padding-bottom: 10px;
}

.form-group-checkbox .checkbox-label {
    margin-bottom: 0;
    white-space: nowrap;
}
</style>
