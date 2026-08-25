<?php

declare(strict_types=1);

/**
 * Mikrotik Watch - Cron de Coleta de Dados
 *
 * Script executado via crontab para coletar métricas dos equipamentos Mikrotik.
 * Execute: php cron/collect.php
 *
 * Exemplo de crontab:
 * */5 * * * * cd /var/www/Mikrotik\ Watch/src && php cron/collect.php >> /var/log/mikrotik-watch/cron.log 2>&1
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';

// Configurar timezone
date_default_timezone_set($config['app']['timezone']);

$logFile = '/var/log/mikrotik-watch/cron.log';

function logMessage(string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    $line = "[{$timestamp}] {$message}" . PHP_EOL;
    echo $line;

    // Tentar gravar em log (ignorar erro se não tiver permissão)
    @file_put_contents($GLOBALS['logFile'], $line, FILE_APPEND);
}

logMessage("=== Início da coleta ===");

try {
    // TODO: Implementar lógica de coleta
    // 1. Listar equipamentos ativos do banco
    // 2. Conectar via MikrotikClient
    // 3. Coletar interfaces e tráfego
    // 4. Salvar métricas no banco

    logMessage("Coleta concluída (placeholder - não implementado)");
} catch (\Throwable $e) {
    logMessage("ERRO: " . $e->getMessage());
    exit(1);
}

logMessage("=== Fim da coleta ===");
