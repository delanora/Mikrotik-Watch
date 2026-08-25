<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MikrotikClient;
use App\Service\CredentialCrypto;
use App\Exception\MikrotikApiException;

/**
 * Mikrotik Watch - Mikrotik Controller
 *
 * Gerenciamento dos equipamentos Mikrotik RouterOS.
 */
class MikrotikController
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Lista todos os equipamentos.
     */
    public function index(): void
    {
        $db = $this->getDb();
        $stmt = $db->query('
            SELECT m.*, c.name AS client_name
            FROM mikrotiks m
            LEFT JOIN clients c ON c.id = m.client_id
            WHERE m.active = true
            ORDER BY c.name, m.name
        ');
        $mikrotiks = $stmt->fetchAll();

        $pageTitle = 'Equipamentos';
        require __DIR__ . '/../../views/mikrotiks/index.php';
    }

    /**
     * Formulário de criação.
     */
    public function create(): void
    {
        $db = $this->getDb();
        $clients = $db->query('SELECT id, name FROM clients WHERE active = true ORDER BY name')->fetchAll();

        $pageTitle = 'Novo Equipamento';
        require __DIR__ . '/../../views/mikrotiks/form.php';
    }

    /**
     * Salva um novo equipamento.
     */
    public function store(): void
    {
        $db = $this->getDb();
        $crypto = $this->getCrypto();

        $clientId = $_POST['client_id'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $host = trim($_POST['host'] ?? '');
        $port = (int) ($_POST['port'] ?? 443);
        $useSsl = isset($_POST['use_ssl']);
        $username = trim($_POST['username'] ?? 'admin');
        $password = $_POST['password'] ?? '';

        if ($name === '' || $host === '' || $clientId === '') {
            $this->redirect('/mikrotiks/create', 'Preencha todos os campos obrigatórios.');
            return;
        }

        $encryptedPassword = $crypto->encrypt($password);

        $stmt = $db->prepare('
            INSERT INTO mikrotiks (client_id, name, host, port, use_ssl, username, password_encrypted)
            VALUES (:client_id, :name, :host, :port, :use_ssl, :username, :password_encrypted)
        ');
        $stmt->execute([
            ':client_id'          => $clientId,
            ':name'               => $name,
            ':host'               => $host,
            ':port'               => $port,
            ':use_ssl'            => $useSsl,
            ':username'           => $username,
            ':password_encrypted' => hex2bin(substr($encryptedPassword, 2)), // armazenar como BYTEA
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

        // Buscar health_log recente
        $stmt = $db->prepare('
            SELECT * FROM health_log
            WHERE mikrotik_id = :id
            ORDER BY collected_at DESC
            LIMIT 24
        ');
        $stmt->execute([':id' => $id]);
        $healthLogs = $stmt->fetchAll();

        $pageTitle = $mikrotik['name'];
        require __DIR__ . '/../../views/mikrotiks/show.php';
    }

    /**
     * Formulário de edição.
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

        $clients = $db->query('SELECT id, name FROM clients WHERE active = true ORDER BY name')->fetchAll();

        $pageTitle = 'Editar: ' . $mikrotik['name'];
        require __DIR__ . '/../../views/mikrotiks/form.php';
    }

    /**
     * Atualiza um equipamento.
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
        $username = trim($_POST['username'] ?? 'admin');
        $password = $_POST['password'] ?? '';

        if ($name === '' || $host === '') {
            $this->redirect("/mikrotiks/{$id}/edit", 'Preencha todos os campos obrigatórios.');
            return;
        }

        if ($password !== '') {
            $encryptedPassword = $crypto->encrypt($password);
            $stmt = $db->prepare('
                UPDATE mikrotiks SET
                    name = :name, host = :host, port = :port,
                    use_ssl = :use_ssl, username = :username,
                    password_encrypted = :password_encrypted
                WHERE id = :id
            ');
            $stmt->execute([
                ':name'               => $name,
                ':host'               => $host,
                ':port'               => $port,
                ':use_ssl'            => $useSsl,
                ':username'           => $username,
                ':password_encrypted' => hex2bin(substr($encryptedPassword, 2)),
                ':id'                 => $id,
            ]);
        } else {
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
     * Testa a conexão com o equipamento via API REST.
     */
    public function testConnection(): void
    {
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
        $password = $crypto->decrypt(bin2hex($mikrotik['password_encrypted']));

        $client = new MikrotikClient(
            host: $mikrotik['host'],
            username: $mikrotik['username'],
            password: $password,
            port: (int) $mikrotik['port'],
            useSsl: (bool) $mikrotik['use_ssl'],
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
        global $router;
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = rtrim($uri, '/');

        // Extrair ID da URI (último segmento numérico/UUID)
        if (preg_match('#/([0-9a-f-]{36})$#i', $uri, $matches)) {
            return $matches[1];
        }
        if (preg_match('#/(\d+)$#', $uri, $matches)) {
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
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
