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
     * Lista todos os equipamentos ativos com filtro e ordenação.
     * Ordenação: offline > unknown > online (por status_since), depois por cliente + nome.
     */
    public function index(): void
    {
        $db = $this->getDb();
        $filterClientId = $_GET['client_id'] ?? '';
        $perPage = 10;
        $page = max(1, (int) ($_GET['page'] ?? 1));

        // Filtro por cliente
        $whereClause = 'WHERE m.active = true';
        $params = [];
        if ($filterClientId !== '') {
            $whereClause .= ' AND m.client_id = :client_id';
            $params[':client_id'] = $filterClientId;
        }

        // Total de registros (com filtro)
        $countSql = "SELECT COUNT(*) FROM mikrotiks m {$whereClause}";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $totalRows = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        // Ordenação: offline primeiro, depois unknown, depois online
        $sql = "
            SELECT
                m.id, m.name, m.host, m.port, m.use_ssl, m.username,
                m.current_status, m.status_since, m.last_checked_at,
                m.last_cpu_load, m.last_memory_free, m.last_memory_total,
                m.last_temperature, m.last_voltage,
                m.board_name, m.routeros_version,
                m.active, m.created_at, m.device_type,
                c.name AS client_name, c.id AS client_id
            FROM mikrotiks m
            LEFT JOIN clients c ON c.id = m.client_id
            {$whereClause}
            ORDER BY
                CASE m.current_status
                    WHEN 'offline' THEN 0
                    WHEN 'unknown' THEN 1
                    WHEN 'online' THEN 2
                    ELSE 3
                END,
                m.status_since ASC NULLS LAST,
                c.name ASC,
                m.name ASC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $mikrotiks = $stmt->fetchAll();

        // Clientes para o filtro
        $clients = $db->query('SELECT id, name FROM clients WHERE active = true ORDER BY name')->fetchAll();

        // Resumo
        $summarySql = "
            SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE current_status = 'online') AS online,
                COUNT(*) FILTER (WHERE current_status = 'offline') AS offline,
                COUNT(*) FILTER (WHERE current_status = 'unknown') AS unknown
            FROM mikrotiks
            WHERE active = true
        ";
        $summary = $db->query($summarySql)->fetch();

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
        \App\Middleware\AuthMiddleware::requireAdmin();

        $db = $this->getDb();
        $crypto = $this->getCrypto();

        $clientId = trim($_POST['client_id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $host = trim($_POST['host'] ?? '');
        $deviceType = $_POST['device_type'] ?? 'mikrotik';
        $port = !empty($_POST['port']) ? (int) $_POST['port'] : null;
        $useSsl = isset($_POST['use_ssl']) ? (int) $_POST['use_ssl'] : null;
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $errors = $this->validate($clientId, $name, $host, $deviceType, $username, $password);

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

        if ($deviceType === 'mikrotik') {
            // Mikrotik: criptografar senha e salvar com campos da API
            $encryptedBase64 = $crypto->encrypt($password);

            $stmt = $db->prepare('
                INSERT INTO mikrotiks (client_id, name, host, device_type, port, use_ssl, username, password_encrypted, current_status)
                VALUES (:client_id, :name, :host, :device_type, :port, :use_ssl, :username, decode(:password_encrypted, \'base64\'), :current_status)
            ');
            $stmt->execute([
                ':client_id'          => $clientId,
                ':name'               => $name,
                ':host'               => $host,
                ':device_type'        => $deviceType,
                ':port'               => $port,
                ':use_ssl'            => $useSsl,
                ':username'           => $username,
                ':password_encrypted' => $encryptedBase64,
                ':current_status'     => 'unknown',
            ]);
        } else {
            // Ping: salvar sem credenciais da API
            $stmt = $db->prepare('
                INSERT INTO mikrotiks (client_id, name, host, device_type, current_status)
                VALUES (:client_id, :name, :host, :device_type, :current_status)
            ');
            $stmt->execute([
                ':client_id'     => $clientId,
                ':name'          => $name,
                ':host'          => $host,
                ':device_type'   => $deviceType,
                ':current_status' => 'unknown',
            ]);
        }

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
        \App\Middleware\AuthMiddleware::requireAdmin();

        $id = $this->extractId();
        $db = $this->getDb();
        $crypto = $this->getCrypto();

        $name = trim($_POST['name'] ?? '');
        $host = trim($_POST['host'] ?? '');
        $port = (int) ($_POST['port'] ?? 443);
        $useSsl = (int) isset($_POST['use_ssl']);
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
        \App\Middleware\AuthMiddleware::requireAdmin();

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

    /**
     * Retorna dados de saúde como JSON para os gráficos (AJAX).
     * Parâmetros GET: start (ISO date), end (ISO date)
     */
    public function healthData(): void
    {
        $id = $this->extractId();
        $db = $this->getDb();

        // Verificar se o equipamento existe
        $stmt = $db->prepare('SELECT id, device_type FROM mikrotiks WHERE id = :id AND active = true');
        $stmt->execute([':id' => $id]);
        $mikrotik = $stmt->fetch();

        if ($mikrotik === false) {
            $this->jsonResponse(404, ['success' => false, 'message' => 'Equipamento não encontrado.']);
            return;
        }

        // Parâmetros de data
        $defaultStart = date('Y-m-d', strtotime('-7 days'));
        $defaultEnd = date('Y-m-d', strtotime('+1 day'));
        $start = $_GET['start'] ?? $defaultStart;
        $end = $_GET['end'] ?? $defaultEnd;

        // Validar formato de data
        if (!preg_match('/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2})?$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2})?$/', $end)) {
            $start = $defaultStart;
            $end = $defaultEnd;
        }

        // Buscar dados de saúde
        $stmt = $db->prepare('
            SELECT cpu_load, memory_free, memory_total, temperature, voltage, uptime, collected_at
            FROM health_log
            WHERE mikrotik_id = :id
              AND collected_at >= :start::timestamptz
              AND collected_at <= :end::timestamptz
            ORDER BY collected_at ASC
        ');
        $stmt->execute([':id' => $id, ':start' => $start, ':end' => $end]);
        $healthLogs = $stmt->fetchAll();

        // Buscar eventos de status (offline/online) no período
        $stmt = $db->prepare('
            SELECT status, started_at, ended_at
            FROM mikrotik_events
            WHERE mikrotik_id = :id
              AND started_at <= :end::timestamptz
              AND (ended_at IS NULL OR ended_at >= :start::timestamptz)
            ORDER BY started_at ASC
        ');
        $stmt->execute([':id' => $id, ':start' => $start, ':end' => $end]);
        $events = $stmt->fetchAll();

        // Buscar status atual para saber se está online agora
        $stmt = $db->prepare('SELECT current_status, status_since FROM mikrotiks WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $statusInfo = $stmt->fetch();

        // Processar dados para os gráficos
        $labels = [];
        $cpuData = [];
        $memData = [];
        $tempData = [];
        $voltData = [];

        foreach ($healthLogs as $log) {
            $labels[] = $log['collected_at'];
            $cpuData[] = $log['cpu_load'] !== null ? (int) $log['cpu_load'] : null;
            $memData[] = ($log['memory_free'] !== null && $log['memory_total'] !== null && $log['memory_total'] > 0)
                ? round((($log['memory_total'] - $log['memory_free']) / $log['memory_total']) * 100, 1)
                : null;
            $tempData[] = $log['temperature'] !== null ? (float) $log['temperature'] : null;
            $voltData[] = $log['voltage'] !== null ? (float) $log['voltage'] : null;
        }

        // Processar eventos para o timeline de uptime
        $uptimeSegments = [];
        $rangeStart = new \DateTimeImmutable($start);
        $rangeEnd = new \DateTimeImmutable($end);

        // Estado inicial: usar o current_status real do equipamento
        $currentState = $statusInfo['current_status'] ?? 'online';
        $currentFrom = $rangeStart;

        // Buscar primeiro evento antes do período para determinar estado inicial
        $stmt = $db->prepare('
            SELECT status, started_at FROM mikrotik_events
            WHERE mikrotik_id = :id AND started_at < :start::timestamptz
            ORDER BY started_at DESC LIMIT 1
        ');
        $stmt->execute([':id' => $id, ':start' => $start]);
        $prevEvent = $stmt->fetch();
        if ($prevEvent) {
            $currentState = $prevEvent['status'];
            $currentFrom = $rangeStart;
        }

        foreach ($events as $event) {
            $eventStart = new \DateTimeImmutable($event['started_at']);

            // Se o estado atual continua até o início deste evento, fechar o segmento
            if ($eventStart > $currentFrom) {
                $segmentEnd = $eventStart > $rangeEnd ? $rangeEnd : $eventStart;
                $uptimeSegments[] = [
                    'status' => $currentState,
                    'from'   => $currentFrom->format('Y-m-d\TH:i:s'),
                    'to'     => $segmentEnd->format('Y-m-d\TH:i:s'),
                ];
            }

            $currentState = $event['status'];
            $currentFrom = $eventStart < $rangeStart ? $rangeStart : $eventStart;
        }

        // Fechar último segmento
        if ($currentFrom < $rangeEnd) {
            $uptimeSegments[] = [
                'status' => $currentState,
                'from'   => $currentFrom->format('Y-m-d\TH:i:s'),
                'to'     => $rangeEnd->format('Y-m-d\TH:i:s'),
            ];
        }

        $this->jsonResponse(200, [
            'success'  => true,
            'labels'   => $labels,
            'cpu'      => $cpuData,
            'memory'   => $memData,
            'temp'     => $tempData,
            'voltage'  => $voltData,
            'uptime'   => $uptimeSegments,
            'status'   => $statusInfo['current_status'] ?? 'unknown',
        ]);
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

    private function validate(string $clientId, string $name, string $host, string $deviceType, string $username, string $password): array
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

        // Validações específicas para Mikrotik
        if ($deviceType === 'mikrotik') {
            if ($username === '') {
                $errors[] = 'O usuário é obrigatório para equipamentos Mikrotik.';
            }
            if ($password === '') {
                $errors[] = 'A senha é obrigatória para equipamentos Mikrotik.';
            }
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
