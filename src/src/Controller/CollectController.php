<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Mikrotik Watch - Collect Controller
 *
 * Endpoint para disparar a coleta de dados manualmente.
 */
class CollectController
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Dispara a coleta de métricas e netwatch via AJAX.
     * Retorna JSON com o resultado.
     */
    public function trigger(): void
    {
        header('Content-Type: application/json');

        $scripts = [
            'collect'          => __DIR__ . '/../../cron/collect.php',
            'collect_netwatch' => __DIR__ . '/../../cron/collect_netwatch.php',
        ];

        $results = [];

        foreach ($scripts as $name => $script) {
            $cmd = sprintf(
                'php %s 2>&1',
                escapeshellarg($script)
            );

            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);

            $results[$name] = [
                'success' => $exitCode === 0,
                'output'  => implode("\n", array_slice($output, -10)), // últimas 10 linhas
                'exit'    => $exitCode,
            ];
        }

        $allSuccess = !in_array(false, array_column($results, 'success'), true);

        echo json_encode([
            'success' => $allSuccess,
            'message' => $allSuccess
                ? 'Coleta executada com sucesso!'
                : 'Alguns scripts falharam. Verifique os logs.',
            'results' => $results,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
