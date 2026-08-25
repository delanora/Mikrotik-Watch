<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Mikrotik Watch - Dashboard Controller
 *
 * Página principal com resumo de status dos equipamentos e hosts.
 */
class DashboardController
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Exibe a página principal do dashboard.
     */
    public function index(): void
    {
        $db = $this->getDb();

        // ─── Resumo geral ─────────────────────────────────────────────────────

        $stmt = $db->query('
            SELECT
                COUNT(*) FILTER (WHERE active = true) AS total_mikrotiks,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'online\') AS online_mikrotiks,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'offline\') AS offline_mikrotiks,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'unknown\') AS unknown_mikrotiks
            FROM mikrotiks
        ');
        $summary = $stmt->fetch();

        $stmt = $db->query('
            SELECT
                COUNT(*) FILTER (WHERE active = true) AS total_hosts,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'up\') AS up_hosts,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'down\') AS down_hosts,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'unknown\') AS unknown_hosts
            FROM netwatch_hosts
        ');
        $hostSummary = $stmt->fetch();

        // ─── Mikrotiks offline ────────────────────────────────────────────────

        $stmt = $db->query('
            SELECT
                m.id, m.name, m.host, m.current_status, m.status_since, m.last_checked_at,
                c.name AS client_name, c.id AS client_id
            FROM mikrotiks m
            LEFT JOIN clients c ON c.id = m.client_id
            WHERE m.active = true AND m.current_status = \'offline\'
            ORDER BY m.status_since ASC NULLS LAST
        ');
        $offlineMikrotiks = $stmt->fetchAll();

        // ─── Hosts offline (Netwatch) ─────────────────────────────────────────

        $stmt = $db->query('
            SELECT
                nh.id, nh.host_address, nh.comment, nh.current_status,
                nh.status_since, nh.last_checked_at,
                m.name AS mikrotik_name, m.id AS mikrotik_id,
                c.name AS client_name, c.id AS client_id
            FROM netwatch_hosts nh
            LEFT JOIN mikrotiks m ON m.id = nh.mikrotik_id
            LEFT JOIN clients c ON c.id = m.client_id
            WHERE nh.active = true AND nh.current_status = \'down\'
            ORDER BY nh.status_since ASC NULLS LAST
        ');
        $downHosts = $stmt->fetchAll();

        $pageTitle = 'Dashboard';
        require __DIR__ . '/../../views/layouts/sidebar.php';
        require __DIR__ . '/../../views/dashboard/index.php';
        require __DIR__ . '/../../views/layouts/footer.php';
    }

    /**
     * Retorna estatísticas do dashboard (para AJAX).
     */
    public function stats(): void
    {
        $db = $this->getDb();

        $stmt = $db->query('
            SELECT
                (SELECT COUNT(*) FROM mikrotiks WHERE active = true) AS total_mikrotiks,
                (SELECT COUNT(*) FROM mikrotiks WHERE active = true AND current_status = \'online\') AS online_mikrotiks,
                (SELECT COUNT(*) FROM mikrotiks WHERE active = true AND current_status = \'offline\') AS offline_mikrotiks,
                (SELECT COUNT(*) FROM netwatch_hosts WHERE active = true) AS total_hosts,
                (SELECT COUNT(*) FROM netwatch_hosts WHERE active = true AND current_status = \'up\') AS up_hosts,
                (SELECT COUNT(*) FROM netwatch_hosts WHERE active = true AND current_status = \'down\') AS down_hosts
        ');
        $stats = $stmt->fetch();

        header('Content-Type: application/json');
        echo json_encode($stats, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

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
