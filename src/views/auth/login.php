<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Mikrotik Watch</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="login-page">

    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                <h1>Mikrotik Watch</h1>
                <p>Painel de Monitoramento</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error" style="margin-bottom: 20px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="/login">
                <?= \App\Middleware\CsrfMiddleware::field() ?>
                <div class="form-group">
                    <label for="email">E-mail</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="seu@email.com"
                        required
                        autofocus
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="password">Senha</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="••••••••"
                        required
                    >
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="margin-top: 8px;">
                    Entrar
                </button>
            </form>
        </div>

        <div class="footer" style="text-align: center; margin-top: 24px; font-size: 12px;">
            &copy; <?= date('Y') ?> Mikrotik Watch
        </div>
    </div>

</body>
</html>
