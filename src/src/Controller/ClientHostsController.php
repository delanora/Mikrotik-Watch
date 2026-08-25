<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Mikrotik Watch - Client Hosts Controller
 *
 * Lista todos os hosts Netwatch de todos os Mikrotiks de um cliente.
 */
class ClientHostsController
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Lista hosts Netwatch do cliente, com seletor de cliente.
     */
    public function index(): void
    {
        $db = $this->getDb();

        // Extrair client_id da URI
        $clientId = $this->extractClientId();

        // Buscar cliente
        $stmt = $db->prepare('SELECT id, name FROM clients WHERE id = :id');
        $stmt->execute([':id' => $clientId]);
        $client = $stmt->fetch();

        if ($client === false) {
            http_response_code(404);
            echo 'Cliente não encontrado.';
            return;
        }

        // Todos os clientes (para o seletor)
        $clients = $db->query('SELECT id, name FROM clients WHERE active = true ORDER BY name')->fetchAll();

        // Buscar Mikrotiks do cliente
        $stmt = $db->prepare('SELECT id, name FROM mikrotiks WHERE client_id = :client_id AND active = true ORDER BY name');
        $stmt->execute([':client_id' => $clientId]);
        $mikrotiks = $stmt->fetchAll();

        // Buscar todos os hosts dos Mikrotiks deste cliente
        $hosts = [];
        if (!empty($mikrotiks)) {
            $mikrotikIds = array_column($mikrotiks, 'id');
            $placeholders = implode(',', array_fill(0, count($mikrotikIds), '?'));

            $stmt = $db->prepare("
                SELECT
                    nh.id, nh.host_address, nh.comment, nh.current_status,
                    nh.status_since, nh.last_checked_at, nh.last_rtt_ms,
                    m.name AS mikrotik_name, m.id AS mikrotik_id
                FROM netwatch_hosts nh
                INNER JOIN mikrotiks m ON m.id = nh.mikrotik_id
                WHERE nh.mikrotik_id IN ({$placeholders})
                  AND nh.active = true
                ORDER BY
                    CASE nh.current_status
                        WHEN 'down' THEN 0
                        WHEN 'unknown' THEN 1
                        WHEN 'up' THEN 2
                        ELSE 3
                    END,
                    nh.status_since ASC NULLS LAST,
                    m.name ASC,
                    nh.host_address ASC
            ");

            foreach ($mikrotikIds as $i => $id) {
                $stmt->bindValue($i + 1, $id, \PDO::PARAM_STR);
            }
            $stmt->execute();
            $hosts = $stmt->fetchAll();
        }

        $pageTitle = 'Hosts — ' . $client['name'];
        require __DIR__ . '/../../views/layouts/sidebar.php';
        require __DIR__ . '/../../views/clients/hosts.php';
        require __DIR__ . '/../../views/layouts/footer.php';
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function extractClientId(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = rtrim($uri, '/');

        if (preg_match('#/clients/([0-9a-f-]{36})(?:/|$)#i', $uri, $matches)) {
            return $matches[1];
        }
        if (preg_match('#/clients/(\d+)(?:/|$)#', $uri, $matches)) {
            return $matches[1];
        }

        http_response_code(400);
        echo 'ID do cliente inválido.';
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
