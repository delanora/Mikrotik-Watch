<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Crypto;

/**
 * Mikrotik Watch - Auth Controller
 *
 * Responsável por autenticação e controle de acesso via sessão PHP.
 */
class AuthController
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Exibe o formulário de login.
     */
    public function loginForm(): void
    {
        session_start();

        // Já está logado? Redireciona para o dashboard
        if (isset($_SESSION['user_id'])) {
            header('Location: /dashboard');
            exit;
        }

        $error = $_SESSION['login_error'] ?? null;
        unset($_SESSION['login_error']);

        $pageTitle = 'Login';
        require __DIR__ . '/../../views/auth/login.php';
    }

    /**
     * Processa o login.
     */
    public function login(): void
    {
        session_start();

        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $_SESSION['login_error'] = 'Preencha todos os campos.';
            header('Location: /login');
            exit;
        }

        // Buscar usuário no banco
        $db = $this->getDb();
        $stmt = $db->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user === false || !Crypto::verifyPassword($password, $user['password_hash'])) {
            $_SESSION['login_error'] = 'E-mail ou senha incorretos.';
            header('Location: /login');
            exit;
        }

        // Login bem-sucedido — configurar sessão
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();

        header('Location: /dashboard');
        exit;
    }

    /**
     * Encerra a sessão do usuário.
     */
    public function logout(): void
    {
        session_start();

        // Remover dados da sessão
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();

        header('Location: /login');
        exit;
    }

    /**
     * Obtém uma conexão PDO com o banco de dados.
     */
    private function getDb(): \PDO
    {
        $db = $this->config['database'];
        $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']}";

        return new \PDO($dsn, $db['user'], $db['password'], [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
}
