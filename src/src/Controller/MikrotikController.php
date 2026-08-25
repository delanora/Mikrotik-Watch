<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MikrotikClient;
use App\Service\CredentialCrypto;
use App\Service\Http\MockTransport;
use App\Exception\MikrotikApiException;

/**
 * Mikrotik Watch - Mikrotik Controller
 *
 * CRUD completo para gerenciamento de equipamentos Mikrotik RouterOS.
 * Senhas criptografadas com CredentialCrypto (sodium_crypto_secretbox).
 */
class MikrotikController
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // ─── CRUD ─────────────────────────────────────────────────────────────────

    /**
     * Lista todos os equipamentos ativos.
     */
    public function index(): void
    {
        $db = $this->getDb();
        $stmt = $db->query('
            SELECT
                m.id, m.name, m.host, m.port, m.use_ssl, m.username,
                m.current_status, m.status_since, m.last_checked_at,
                m.last_cpu_load, m.board_name, m.routeros_version,
                m.active, m.created_at,
                c.name AS client_name, c.id AS client_id
            FROM mikrotiks m
            LEFT JOIN clients c ON c.id = m.client_id
            WHERE m.active = true
            ORDER BY c.name, m.name
        ');
        $mikrotiks = $stmt->fetchAll();

        $pageTitle = 'Equipamentos';
        require __DIR__ . '/../../views/layouts/sidebar.php';
        require __DIR__ . '/../../views/mikrotiks/index.php';
        require __DIR__ . '/../../views/layouts/footer.php';
    }

    /**
     * Formulário de criação.
     */
    public function create(): void
    {
        $db = $this->getDb();
        $mikrotik = null;
        $clients = $db->query('SELECT id, name FROM clients WHERE active = true ORDER BY name')->fetchAll();
        $errors = [];

        $pageTitle = 'Novo Equipamento';
        require __DIR__ . '/../../views/layouts/sidebar.php';
        require __DIR__ . '/../../views/mikrotiks/form.php';
        require __DIR__ . '/../../views/layouts/footer.php';
    }

    /**
     * Salva um novo equipamento com senha criptografada.
     */
    public function store(): void
    {
        $db = $this->getDb();
        $crypto = $this->getCrypto();

        $clientId = trim($_POST['client_id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $host = trim($_POST['host'] ?? '');
        $port = (int) ($_POST['port'] ?? 443);
        $useSsl = isset($_POST['use_ssl']);
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $errors = $this->validate($clientId, $name, $host, $username, $password);

        if (!empty($errors)) {
            $mikrotik = $_POST;
            $clients = $this->getClients();
            $pageTitle = 'Novo Equipamento';
            http_response_code(422);
            require __DIR__ . '/../../views/layouts/sidebar.php';
            require __DIR__ . '/../../views/mikrotiks/form.php';
            require __DIR__ . '/../../views/layouts/footer.php';
            return;
        }

        // Criptografar senha com CredentialCrypto e armazenar como BYTEA
        $encryptedBase64 = $crypto->encrypt($password);
        $encryptedBytes = base64_decode($encryptedBase64, true);

        $stmt = $db->prepare('
            INSERT INTO mikrotiks (client_id, name, host, port, use_ssl, username, password_encrypted, current_status)
            VALUES (:client_id, :name, :host, :port, :use_ssl, :username, decode(:password_encrypted, \'base64\'), :current_status)
        ');
        $stmt->execute([
            ':client_id'          => $clientId,
            ':name'               => $name,
            ':host'               => $host,
            ':port'               => $port,
            ':use_ssl'            => $useSsl,
            ':username'           => $username,
            ':password_encrypted' => $encryptedBase64,
            ':current_status'     => 'unknown',
        ]);

        header('Location: /mikrotiks');
        exit;
    }

    /**
     * Exibe detalhes de um equipamento.
     */
    public function show(): void
    {
        $id = $this->extractId();
        $db = $this->getDb();

        $stmt = $db->prepare('
            SELECT m.*, c.name AS client_name
            FROM mikrotiks m
            LEFT JOIN clients c ON c.id = m.client_id
            WHERE m.id = :id AND m.active = true
        ');
        $stmt->execute([':id' => $id]);
        $mikrotik = $stmt->fetch();

        if ($mikrotik === false) {
            http_response_code(404);
            echo 'Equipamento não encontrado.';
            return;
        }

        $stmt = $db->prepare('
            SELECT * FROM health_log
            WHERE mikrotik_id = :id
            ORDER BY collected_at DESC
            LIMIT 24
        ');
        $stmt->execute([':id' => $id]);
        $healthLogs = $stmt->fetchAll();

        $pageTitle = $mikrotik['name'];
        require __DIR__ . '/../../views/layouts/sidebar.php';
        require __DIR__ . '/../../views/mikrotiks/show.php';
        require __DIR__ . '/../../views/layouts/footer.php';
    }

    /**
     * Formulário de edição — senha nunca é exibida.
     */
    public function edit(): void
    {
        $id = $this->extractId();
        $db = $this->getDb();

        $stmt = $db->prepare('SELECT * FROM mikrotiks WHERE id = :id AND active = true');
        $stmt->execute([':id' => $id]);
        $mikrotik = $stmt->fetch();

        if ($mikrotik === false) {
            http_response_code(404);
            echo 'Equipamento não encontrado.';
            return;
        }

        $clients = $this->getClients();
        $errors = [];

        $pageTitle = 'Editar: ' . $mikrotik['name'];
        require __DIR__ . '/../../views/layouts/sidebar.php';
        require __DIR__ . '/../../views/mikrotiks/form.php';
        require __DIR__ . '/../../views/layouts/footer.php';
    }

    /**
     * Atualiza um equipamento. Senha só atualiza se preenchida.
     */
    public function update(): void
    {
        $id = $this->extractId();
        $db = $this->getDb();
        $crypto = $this->getCrypto();

        $name = trim($_POST['name'] ?? '');
        $host = trim($_POST['host'] ?? '');
        $port = (int) ($_POST['port'] ?? 443);
        $useSsl = isset($_POST['use_ssl']);
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $errors = $this->validateUpdate($name, $host, $username);

        if (!empty($errors)) {
            $mikrotik = array_merge(
                $this->findMikrotik($id) ?? [],
                $_POST,
                ['id' => $id]
            );
            $clients = $this->getClients();
            $pageTitle = 'Editar: ' . $name;
            http_response_code(422);
            require __DIR__ . '/../../views/layouts/sidebar.php';
            require __DIR__ . '/../../views/mikrotiks/form.php';
            require __DIR__ . '/../../views/layouts/footer.php';
            return;
        }

        if ($password !== '') {
            // Senha preenchida → criptografar e atualizar
            $encryptedBase64 = $crypto->encrypt($password);

            $stmt = $db->prepare('
                UPDATE mikrotiks SET
                    name = :name, host = :host, port = :port,
                    use_ssl = :use_ssl, username = :username,
                    password_encrypted = decode(:password_encrypted, \'base64\')
                WHERE id = :id
            ');
            $stmt->execute([
                ':name'               => $name,
                ':host'               => $host,
                ':port'               => $port,
                ':use_ssl'            => $useSsl,
                ':username'           => $username,
                ':password_encrypted' => $encryptedBase64,
                ':id'                 => $id,
            ]);
        } else {
            // Senha vazia → manter a anterior
            $stmt = $db->prepare('
                UPDATE mikrotiks SET
                    name = :name, host = :host, port = :port,
                    use_ssl = :use_ssl, username = :username
                WHERE id = :id
            ');
            $stmt->execute([
                ':name'     => $name,
                ':host'     => $host,
                ':port'     => $port,
                ':use_ssl'  => $useSsl,
                ':username' => $username,
                ':id'       => $id,
            ]);
        }

        header('Location: /mikrotiks');
        exit;
    }

    /**
     * Remove (soft delete) um equipamento.
     */
    public function delete(): void
    {
        $id = $this->extractId();
        $db = $this->getDb();

        $stmt = $db->prepare('UPDATE mikrotiks SET active = false WHERE id = :id');
        $stmt->execute([':id' => $id]);

        header('Location: /mikrotiks');
        exit;
    }

    /**
     * Testa a conexão via API REST usando dados do formulário (AJAX).
     * Aceita tanto dados do formulário (POST JSON) quanto dados do banco (por ID na URL).
     */
    public function testConnection(): void
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            // Dados do formulário via AJAX (antes de salvar)
            $json = json_decode(file_get_contents('php://input'), true);

            $host = trim($json['host'] ?? '');
            $port = (int) ($json['port'] ?? 443);
            $useSsl = filter_var($json['use_ssl'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $username = trim($json['username'] ?? '');
            $password = $json['password'] ?? '';
        } else {
            // Dados do banco (por ID na URL)
            $id = $this->extractId();
            $db = $this->getDb();

            $stmt = $db->prepare('SELECT * FROM mikrotiks WHERE id = :id AND active = true');
            $stmt->execute([':id' => $id]);
            $mikrotik = $stmt->fetch();

            if ($mikrotik === false) {
                $this->jsonResponse(404, ['success' => false, 'message' => 'Equipamento não encontrado.']);
                return;
            }

            $crypto = $this->getCrypto();
            $encryptedBase64 = base64_encode($mikrotik['password_encrypted']);
            $password = $crypto->decrypt($encryptedBase64);

            $host = $mikrotik['host'];
            $port = (int) $mikrotik['port'];
            $useSsl = (bool) $mikrotik['use_ssl'];
            $username = $mikrotik['username'];
        }

        if ($host === '' || $username === '' || $password === '') {
            $this->jsonResponse(422, [
                'success' => false,
                'message' => 'Preencha host, usuário e senha para testar a conexão.',
            ]);
            return;
        }

        $client = new MikrotikClient(
            host: $host,
            username: $username,
            password: $password,
            port: $port,
            useSsl: $useSsl,
            verifySsl: !$this->config['mikrotik']['allow_self_signed'],
            timeout: $this->config['mikrotik']['default_timeout'],
        );

        try {
            $resource = $client->systemResource();
            $this->jsonResponse(200, [
                'success'  => true,
                'message'  => 'Conexão estabelecida com sucesso!',
                'resource' => $resource,
            ]);
        } catch (MikrotikApiException $e) {
            $this->jsonResponse(503, [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function extractId(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = rtrim($uri, '/');

        // UUID: /mikrotiks/{uuid} ou /mikrotiks/{uuid}/edit etc.
        if (preg_match('#/mikrotiks/([0-9a-f-]{36})(?:/|$)#i', $uri, $matches)) {
            return $matches[1];
        }
        // ID numérico
        if (preg_match('#/mikrotiks/(\d+)(?:/|$)#', $uri, $matches)) {
            return $matches[1];
        }

        http_response_code(400);
        echo 'ID inválido.';
        exit;
    }

    private function findMikrotik(string $id): ?array
    {
        $db = $this->getDb();
        $stmt = $db->prepare('SELECT * FROM mikrotiks WHERE id = :id AND active = true');
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    private function getClients(): array
    {
        $db = $this->getDb();
        return $db->query('SELECT id, name FROM clients WHERE active = true ORDER BY name')->fetchAll();
    }

    private function validate(string $clientId, string $name, string $host, string $username, string $password): array
    {
        $errors = [];

        if ($clientId === '') {
            $errors[] = 'Selecione um cliente.';
        }
        if ($name === '') {
            $errors[] = 'O nome do equipamento é obrigatório.';
        } elseif (mb_strlen($name) > 150) {
            $errors[] = 'O nome não pode ter mais de 150 caracteres.';
        }
        if ($host === '') {
            $errors[] = 'O host (IP ou DDNS) é obrigatório.';
        }
        if ($username === '') {
            $errors[] = 'O usuário é obrigatório.';
        }
        if ($password === '') {
            $errors[] = 'A senha é obrigatória.';
        }

        return $errors;
    }

    private function validateUpdate(string $name, string $host, string $username): array
    {
        $errors = [];

        if ($name === '') {
            $errors[] = 'O nome do equipamento é obrigatório.';
        }
        if ($host === '') {
            $errors[] = 'O host (IP ou DDNS) é obrigatório.';
        }
        if ($username === '') {
            $errors[] = 'O usuário é obrigatório.';
        }

        return $errors;
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

    private function getCrypto(): CredentialCrypto
    {
        $key = $this->config['mikrotik']['credential_key'] ?? '';
        return new CredentialCrypto($key);
    }

    private function redirect(string $url, string $error = ''): void
    {
        if ($error !== '') {
            session_start();
            $_SESSION['flash_error'] = $error;
        }
        header("Location: {$url}");
        exit;
    }

    private function jsonResponse(int $status, array $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
