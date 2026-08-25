<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Mikrotik Watch - Dashboard Controller
 *
 * Responsável pela página principal e dados do dashboard.
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
        // TODO: Implementar lógica do dashboard
        echo "Dashboard - Em desenvolvimento";
    }

    /**
     * Retorna estatísticas do dashboard (para AJAX).
     */
    public function stats(): void
    {
        // TODO: Implementar retorna de estatísticas
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ok',
            'message' => 'Stats endpoint - Em desenvolvimento',
        ]);
    }
}
