<?php
declare(strict_types=1);
/**
 * @var array|null $client  Dados do cliente (null = criação)
 * @var array      $errors  Lista de erros de validação
 */
$isEdit = !empty($client['id']);
$formAction = $isEdit ? "/clients/{$client['id']}" : '/clients';
?>

<div class="breadcrumb">
    <a href="/clients">Clientes</a>
    <span class="breadcrumb-sep">›</span>
    <span class="breadcrumb-current"><?= $isEdit ? 'Editar' : 'Novo Cliente' ?></span>
</div>

<div class="page-header">
    <h1>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        <?= $isEdit ? 'Editar Cliente' : 'Novo Cliente' ?>
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
            <?= $isEdit ? 'Dados do Cliente' : 'Informações do Cliente' ?>
        </h2>
    </div>
    <div class="card-body">
        <form method="POST" action="<?= htmlspecialchars($formAction) ?>">
            <?= \App\Middleware\CsrfMiddleware::field() ?>

            <div class="form-group">
                <label for="name">Nome do Cliente *</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    value="<?= htmlspecialchars($client['name'] ?? '') ?>"
                    placeholder="Ex: João da Silva"
                    required
                    maxlength="200"
                >
                <small>Nome que identifica o cliente no painel.</small>
            </div>

            <div class="form-group">
                <label for="telegram_group_id">ID do Grupo Telegram</label>
                <input
                    type="text"
                    id="telegram_group_id"
                    name="telegram_group_id"
                    value="<?= htmlspecialchars((string) ($client['telegram_group_id'] ?? '')) ?>"
                    placeholder="Ex: -1001234567890"
                >
                <small>ID numérico do grupo Telegram para envio de alertas. Opcional — pode ser negativo.</small>
            </div>

            <?php if ($isEdit && (int) ($client['mikrotik_count'] ?? 0) > 0): ?>
                <div class="alert alert-warning">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <div>Este cliente possui <?= (int) $client['mikrotik_count'] ?> equipamento(s) vinculado(s). Alterar o nome não afeta os equipamentos.</div>
                </div>
            <?php endif; ?>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <?= $isEdit ? 'Salvar Alterações' : 'Criar Cliente' ?>
                </button>
                <a href="/clients" class="btn btn-secondary">Cancelar</a>
            </div>

        </form>
    </div>
</div>
