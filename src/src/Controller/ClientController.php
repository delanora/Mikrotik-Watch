<?php

declare(strict_types=1);

namespace App\Controller;

use PDO;

/**
 * Mikrotik Watch - Client Controller
 *
 * CRUD completo para gerenciamento de clientes.
 */
class ClientController
{
    private array $config;
    private PDO $db;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = $this->getDatabase();
    }

    /**
     * Lista todos os clientes com contagem de Mikrotiks vinculados.
     */
    public function index(): void
    {
        $perPage = 10;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        // Total de registros
        $totalStmt = $this->db->query('SELECT COUNT(*) FROM clients');
        $totalRows = (int) $totalStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page = min($page, $totalPages);

        $stmt = $this->db->prepare("
            SELECT
                c.*,
                COALESCE(m.mikrotik_count, 0) AS mikrotik_count,
                COALESCE(m.online_count, 0)   AS online_count,
                COALESCE(m.offline_count, 0)  AS offline_count,
                COALESCE(m.warning_count, 0)  AS warning_count
            FROM clients c
            LEFT JOIN (
                SELECT
                    client_id,
                    COUNT(*)                                         AS mikrotik_count,
                    COUNT(*) FILTER (WHERE current_status = 'online')  AS online_count,
                    COUNT(*) FILTER (WHERE current_status = 'offline') AS offline_count,
                    COUNT(*) FILTER (WHERE current_status = 'warning') AS warning_count
                FROM mikrotiks
                WHERE active = true
                GROUP BY client_id
            ) m ON m.client_id = c.id
            ORDER BY c.name ASC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $clients = $stmt->fetchAll();

        $pageTitle = 'Clientes';
        require __DIR__ . '/../../views/layouts/sidebar.php';
        require __DIR__ . '/../../views/clients/index.php';
        require __DIR__ . '/../../views/layouts/footer.php';
    }

    /**
     * Exibe o formulário de criação de cliente.
     */
    public function create(): void
    {
        $client = null;
        $errors = [];
        $pageTitle = 'Novo Cliente';
        require __DIR__ . '/../../views/layouts/sidebar.php';
        require __DIR__ . '/../../views/clients/form.php';
        require __DIR__ . '/../../views/layouts/footer.php';
    }

    /**
     * Valida e insere um novo cliente no banco.
     */
    public function store(): void
    {
        \App\Middleware\AuthMiddleware::requireAdmin();

        $name = trim($_POST['name'] ?? '');
        $telegramGroupId = trim($_POST['telegram_group_id'] ?? '');

        $errors = $this->validate($name, $telegramGroupId);

        if (!empty($errors)) {
            $client = [
                'name' => $name,
                'telegram_group_id' => $telegramGroupId,
            ];
            $pageTitle = 'Novo Cliente';
            http_response_code(422);
            require __DIR__ . '/../../views/layouts/sidebar.php';
            require __DIR__ . '/../../views/clients/form.php';
            require __DIR__ . '/../../views/layouts/footer.php';
            return;
        }

        $stmt = $this->db->prepare('
            INSERT INTO clients (name, telegram_group_id)
            VALUES (:name, :telegram_group_id)
        ');
        $stmt->execute([
            ':name'              => $name,
            ':telegram_group_id' => $telegramGroupId !== '' ? (int) $telegramGroupId : null,
        ]);

        header('Location: /clients');
        exit;
    }

    /**
     * Exibe o formulário de edição de um cliente.
     */
    public function edit(): void
    {
        $id = $this->extractId();
        $client = $this->findClient($id);

        if ($client === null) {
            http_response_code(404);
            echo 'Cliente não encontrado.';
            return;
        }

        $errors = [];
        $pageTitle = 'Editar Cliente';
        require __DIR__ . '/../../views/layouts/sidebar.php';
        require __DIR__ . '/../../views/clients/form.php';
        require __DIR__ . '/../../views/layouts/footer.php';
    }

    /**
     * Valida e atualiza um cliente no banco.
     */
    public function update(): void
    {
        \App\Middleware\AuthMiddleware::requireAdmin();

        $id = $this->extractId();
        $client = $this->findClient($id);

        if ($client === null) {
            http_response_code(404);
            echo 'Cliente não encontrado.';
            return;
        }

        $name = trim($_POST['name'] ?? '');
        $telegramGroupId = trim($_POST['telegram_group_id'] ?? '');

        $errors = $this->validate($name, $telegramGroupId);

        if (!empty($errors)) {
            $client['name'] = $name;
            $client['telegram_group_id'] = $telegramGroupId;
            $pageTitle = 'Editar Cliente';
            http_response_code(422);
            require __DIR__ . '/../../views/layouts/sidebar.php';
            require __DIR__ . '/../../views/clients/form.php';
            require __DIR__ . '/../../views/layouts/footer.php';
            return;
        }

        $stmt = $this->db->prepare('
            UPDATE clients
            SET name = :name, telegram_group_id = :telegram_group_id
            WHERE id = :id
        ');
        $stmt->execute([
            ':name'              => $name,
            ':telegram_group_id' => $telegramGroupId !== '' ? (int) $telegramGroupId : null,
            ':id'                => $id,
        ]);

        header('Location: /clients');
        exit;
    }

    /**
     * Exclui um cliente e todos os dados vinculados (cascade).
     */
    public function destroy(): void
    {
        \App\Middleware\AuthMiddleware::requireAdmin();

        $id = $this->extractId();
        $client = $this->findClient($id);

        if ($client === null) {
            http_response_code(404);
            echo 'Cliente não encontrado.';
            return;
        }

        $stmt = $this->db->prepare('DELETE FROM clients WHERE id = :id');
        $stmt->execute([':id' => $id]);

        header('Location: /clients');
        exit;
    }

    // ─── Métodos auxiliares ───────────────────────────────────────────────────

    /**
     * Extrai o ID da requisição atual a partir das variáveis globais do Router.
     *
     * @return string
     * @throws \RuntimeException Se o ID não estiver disponível
     */
    private function extractId(): string
    {
        // O Router armazena os parâmetros capturados em $_GET['_params']
        // quando o index.php resolve a rota. Precisamos extrair o {id}.
        // Como o index.php não expõe os params diretamente, usamos parsing manual.
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = rtrim($uri, '/');

        // Padrão: /clients/{uuid}/edit ou /clients/{uuid}/delete
        if (preg_match('#/clients/([0-9a-f-]+)(?:/edit|/delete)?$#', $uri, $m)) {
            return $m[1];
        }

        throw new \RuntimeException('ID do cliente não encontrado na URL.');
    }

    /**
     * Busca um cliente pelo ID com contagem de Mikrotiks.
     *
     * @param string $id
     * @return array|null
     */
    private function findClient(string $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT
                c.*,
                COALESCE(m.mikrotik_count, 0) AS mikrotik_count
            FROM clients c
            LEFT JOIN (
                SELECT client_id, COUNT(*) AS mikrotik_count
                FROM mikrotiks
                WHERE active = true
                GROUP BY client_id
            ) m ON m.client_id = c.id
            WHERE c.id = :id
        ');
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Valida os dados do formulário de cliente.
     *
     * @param string $name
     * @param string $telegramGroupId
     * @return array Lista de erros (vazio = válido)
     */
    private function validate(string $name, string $telegramGroupId): array
    {
        $errors = [];

        if ($name === '') {
            $errors[] = 'O nome do cliente é obrigatório.';
        } elseif (mb_strlen($name) > 200) {
            $errors[] = 'O nome do cliente não pode ter mais de 200 caracteres.';
        }

        if ($telegramGroupId !== '') {
            // Aceita negativos (grupos Telegram usam IDs negativos)
            if (!preg_match('/^-?\d+$/', $telegramGroupId)) {
                $errors[] = 'O ID do grupo Telegram deve ser numérico.';
            }
        }

        return $errors;
    }

    /**
     * Obtém uma instância PDO.
     *
     * @return PDO
     */
    private function getDatabase(): PDO
    {
        $config = $this->config['database'];
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $config['host'], $config['port'], $config['name']);

        return new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
}
