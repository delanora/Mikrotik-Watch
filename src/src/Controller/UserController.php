<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Crypto;

/**
 * Mikrotik Watch - User Controller
 *
 * Gerenciamento de usuários do painel (listar, criar).
 */
class UserController
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Lista todos os usuários.
     */
    public function index(): void
    {
        $db = $this->getDb();

        $stmt = $db->query('
            SELECT id, name, email, role, created_at
            FROM users
            ORDER BY created_at DESC
        ');
        $users = $stmt->fetchAll();

        $pageTitle = 'Usuários';
        require __DIR__ . '/../../views/layouts/sidebar.php';
        require __DIR__ . '/../../views/users/index.php';
        require __DIR__ . '/../../views/layouts/footer.php';
    }

    /**
     * Exibe o formulário de criação de usuário.
     */
    public function create(): void
    {
        $errors = [];
        $user = [];

        $pageTitle = 'Novo Usuário';
        require __DIR__ . '/../../views/layouts/sidebar.php';
        require __DIR__ . '/../../views/users/form.php';
        require __DIR__ . '/../../views/layouts/footer.php';
    }

    /**
     * Cria um novo usuário.
     */
    public function store(): void
    {
        $db = $this->getDb();

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'viewer';

        $errors = $this->validate($name, $email, $password, $role);

        if (!empty($errors)) {
            $user = $_POST;
            $pageTitle = 'Novo Usuário';
            http_response_code(422);
            require __DIR__ . '/../../views/layouts/sidebar.php';
            require __DIR__ . '/../../views/users/form.php';
            require __DIR__ . '/../../views/layouts/footer.php';
            return;
        }

        // Verificar email duplicado
        $stmt = $db->prepare('SELECT 1 FROM users WHERE email = :email');
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            $errors[] = 'Este e-mail já está em uso.';
            $user = $_POST;
            $pageTitle = 'Novo Usuário';
            http_response_code(422);
            require __DIR__ . '/../../views/layouts/sidebar.php';
            require __DIR__ . '/../../views/users/form.php';
            require __DIR__ . '/../../views/layouts/footer.php';
            return;
        }

        $passwordHash = Crypto::hashPassword($password);

        $stmt = $db->prepare('
            INSERT INTO users (name, email, password_hash, role)
            VALUES (:name, :email, :password_hash, :role)
        ');
        $stmt->execute([
            ':name'          => $name,
            ':email'         => $email,
            ':password_hash' => $passwordHash,
            ':role'          => $role,
        ]);

        header('Location: /users');
        exit;
    }

    /**
     * Exclui um usuário.
     */
    public function delete(): void
    {
        $id = $this->extractId();
        $db = $this->getDb();

        // Não permitir excluir a si mesmo
        $currentUserId = $_SESSION['user_id'] ?? '';
        if ($id === $currentUserId) {
            header('Location: /users');
            exit;
        }

        $stmt = $db->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);

        header('Location: /users');
        exit;
    }

    /**
     * Valida os dados do usuário.
     */
    private function validate(string $name, string $email, string $password, string $role): array
    {
        $errors = [];

        if ($name === '') {
            $errors[] = 'O nome é obrigatório.';
        }

        if ($email === '') {
            $errors[] = 'O e-mail é obrigatório.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'E-mail inválido.';
        }

        if ($password === '') {
            $errors[] = 'A senha é obrigatória.';
        } elseif (strlen($password) < 6) {
            $errors[] = 'A senha deve ter pelo menos 6 caracteres.';
        }

        if (!in_array($role, ['admin', 'viewer'], true)) {
            $errors[] = 'Papel inválido.';
        }

        return $errors;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function extractId(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = rtrim($uri, '/');

        if (preg_match('#/users/([0-9a-f-]{36})(?:/|$)#i', $uri, $matches)) {
            return $matches[1];
        }

        http_response_code(400);
        echo 'ID inválido.';
        exit;
    }

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
