<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Mikrotik Watch - TV Dashboard Controller
 *
 * Dashboard fullscreen para exibição em televisão.
 * Sem menu lateral, dados essenciais de monitoramento.
 */
class TvController
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Exibe o dashboard TV com todos os dados de monitoramento.
     */
    public function index(): void
    {
        $db = $this->getDb();

        // ─── Resumo de dispositivos ───────────────────────────────────────────

        $stmt = $db->query('
            SELECT
                COUNT(*) FILTER (WHERE active = true) AS total,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'online\') AS online,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'offline\') AS offline,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'unknown\') AS unknown
            FROM mikrotiks
        ');
        $deviceSummary = $stmt->fetch();

        // ─── Resumo de hosts ──────────────────────────────────────────────────

        $stmt = $db->query('
            SELECT
                COUNT(*) FILTER (WHERE active = true) AS total,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'up\') AS up,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'down\') AS down,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'unknown\') AS unknown
            FROM netwatch_hosts
        ');
        $hostSummary = $stmt->fetch();

        // ─── Todos os dispositivos ────────────────────────────────────────────

        $stmt = $db->query('
            SELECT
                m.id, m.name, m.host, m.current_status, m.status_since,
                m.last_cpu_load, m.last_memory_free, m.last_memory_total,
                m.last_temperature, m.last_rtt_ms, m.device_type,
                m.last_checked_at,
                c.name AS client_name
            FROM mikrotiks m
            LEFT JOIN clients c ON c.id = m.client_id
            WHERE m.active = true
            ORDER BY
                CASE m.current_status
                    WHEN \'offline\' THEN 0
                    WHEN \'unknown\' THEN 1
                    WHEN \'online\' THEN 2
                    ELSE 3
                END,
                c.name ASC,
                m.name ASC
        ');
        $devices = $stmt->fetchAll();

        // ─── Hosts down ───────────────────────────────────────────────────────

        $stmt = $db->query('
            SELECT
                nh.host_address, nh.comment, nh.current_status,
                nh.status_since, nh.last_rtt_ms,
                m.name AS mikrotik_name,
                c.name AS client_name
            FROM netwatch_hosts nh
            LEFT JOIN mikrotiks m ON m.id = nh.mikrotik_id
            LEFT JOIN clients c ON c.id = m.client_id
            WHERE nh.active = true AND nh.current_status = \'down\'
            ORDER BY nh.status_since ASC NULLS LAST
            LIMIT 50
        ');
        $downHosts = $stmt->fetchAll();

        // ─── Última coleta ────────────────────────────────────────────────────

        $stmt = $db->query('SELECT MAX(last_checked_at) AS last_check FROM mikrotiks WHERE active = true');
        $lastCheck = $stmt->fetchColumn();

        // ─── Renderizar ───────────────────────────────────────────────────────

        require __DIR__ . '/../../views/tv/index.php';
    }

    /**
     * API JSON para auto-refresh via AJAX.
     */
    public function api(): void
    {
        $db = $this->getDb();

        $stmt = $db->query('
            SELECT
                COUNT(*) FILTER (WHERE active = true) AS total,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'online\') AS online,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'offline\') AS offline
            FROM mikrotiks
        ');
        $deviceSummary = $stmt->fetch();

        $stmt = $db->query('
            SELECT
                COUNT(*) FILTER (WHERE active = true) AS total,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'up\') AS up,
                COUNT(*) FILTER (WHERE active = true AND current_status = \'down\') AS down
            FROM netwatch_hosts
        ');
        $hostSummary = $stmt->fetch();

        $stmt = $db->query('
            SELECT
                m.name, m.host, m.current_status, m.status_since,
                m.last_cpu_load, m.last_memory_free, m.last_memory_total,
                m.last_temperature, m.last_rtt_ms, m.device_type,
                m.last_checked_at,
                c.name AS client_name
            FROM mikrotiks m
            LEFT JOIN clients c ON c.id = m.client_id
            WHERE m.active = true
            ORDER BY
                CASE m.current_status
                    WHEN \'offline\' THEN 0
                    WHEN \'unknown\' THEN 1
                    WHEN \'online\' THEN 2
                    ELSE 3
                END,
                c.name ASC, m.name ASC
        ');
        $devices = $stmt->fetchAll();

        $stmt = $db->query('
            SELECT
                nh.host_address, nh.comment, nh.current_status,
                nh.status_since, nh.last_rtt_ms,
                m.name AS mikrotik_name,
                c.name AS client_name
            FROM netwatch_hosts nh
            LEFT JOIN mikrotiks m ON m.id = nh.mikrotik_id
            LEFT JOIN clients c ON c.id = m.client_id
            WHERE nh.active = true AND nh.current_status = \'down\'
            ORDER BY nh.status_since ASC NULLS LAST
            LIMIT 50
        ');
        $downHosts = $stmt->fetchAll();

        $stmt = $db->query('SELECT MAX(last_checked_at) AS last_check FROM mikrotiks WHERE active = true');
        $lastCheck = $stmt->fetchColumn();

        header('Content-Type: application/json');
        echo json_encode([
            'devices'      => $deviceSummary,
            'hosts'        => $hostSummary,
            'deviceList'   => $devices,
            'downHosts'    => $downHosts,
            'lastCheck'    => $lastCheck,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
