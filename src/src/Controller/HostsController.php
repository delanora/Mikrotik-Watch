<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Mikrotik Watch - Hosts Controller
 *
 * Lista equipamentos Mikrotik e seus hosts Netwatch.
 */
class HostsController
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Lista todos os Mikrotiks ativos com contagem de hosts.
     */
    public function index(): void
    {
        $db = $this->getDb();

        $stmt = $db->query('
            SELECT
                m.id, m.name, m.host, m.current_status,
                c.name AS client_name,
                COALESCE(nh.total_hosts, 0) AS total_hosts,
                COALESCE(nh.up_hosts, 0) AS up_hosts,
                COALESCE(nh.down_hosts, 0) AS down_hosts
            FROM mikrotiks m
            LEFT JOIN clients c ON c.id = m.client_id
            LEFT JOIN (
                SELECT
                    mikrotik_id,
                    COUNT(*) AS total_hosts,
                    COUNT(*) FILTER (WHERE current_status = \'up\') AS up_hosts,
                    COUNT(*) FILTER (WHERE current_status = \'down\') AS down_hosts
                FROM netwatch_hosts
                WHERE active = true
                GROUP BY mikrotik_id
            ) nh ON nh.mikrotik_id = m.id
            WHERE m.active = true
            ORDER BY c.name, m.name
        ');
        $mikrotiks = $stmt->fetchAll();

        $pageTitle = 'Hosts';
        require __DIR__ . '/../../views/layouts/sidebar.php';
        require __DIR__ . '/../../views/hosts/index.php';
        require __DIR__ . '/../../views/layouts/footer.php';
    }

    /**
     * Lista os hosts Netwatch de um Mikrotik específico.
     */
    public function show(): void
    {
        $id = $this->extractId();
        $db = $this->getDb();

        // Buscar Mikrotik
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

        // Buscar hosts
        $stmt = $db->prepare('
            SELECT
                id, host_address, comment, current_status,
                status_since, last_checked_at, last_rtt_ms
            FROM netwatch_hosts
            WHERE mikrotik_id = :mikrotik_id AND active = true
            ORDER BY
                CASE current_status
                    WHEN \'down\' THEN 0
                    WHEN \'unknown\' THEN 1
                    WHEN \'up\' THEN 2
                    ELSE 3
                END,
                status_since ASC NULLS LAST,
                host_address ASC
        ');
        $stmt->execute([':mikrotik_id' => $id]);
        $hosts = $stmt->fetchAll();

        $pageTitle = 'Hosts — ' . $mikrotik['name'];
        require __DIR__ . '/../../views/layouts/sidebar.php';
        require __DIR__ . '/../../views/hosts/show.php';
        require __DIR__ . '/../../views/layouts/footer.php';
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function extractId(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = rtrim($uri, '/');

        if (preg_match('#/hosts/([0-9a-f-]{36})(?:/|$)#i', $uri, $matches)) {
            return $matches[1];
        }
        if (preg_match('#/hosts/(\d+)(?:/|$)#', $uri, $matches)) {
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
