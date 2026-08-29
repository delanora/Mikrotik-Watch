<?php

declare(strict_types=1);

/**
 * Mikrotik Watch - Setup do Banco de Dados de Teste
 *
 * Script para criar e configurar o banco de dados de testes.
 * Execute: php tests/setup_test_db.php
 */

echo "=== Mikrotik Watch - Setup do Banco de Testes ===\n\n";

// Carregar .env se existir
$envPath = dirname(__DIR__) . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value, " \t\n\r\0\x0B\"'"));
    }
}

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '5432';
$dbUser = getenv('DB_USER') ?: 'mikrotik_watch';
$dbPass = getenv('DB_PASSWORD') ?: '';
$testDbName = 'mikrotik_watch_test';

echo "Conectando ao PostgreSQL em {$dbHost}:{$dbPort}...\n";

try {
    // Conectar ao PostgreSQL (sem banco específico)
    $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname=postgres";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Verificar se banco de teste já existe
    $stmt = $pdo->query("SELECT 1 FROM pg_database WHERE datname = '{$testDbName}'");
    if ($stmt->fetch()) {
        echo "Banco de dados '{$testDbName}' já existe. Recriando...\n";
        // Encerrar conexões ativas
        $pdo->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$testDbName}' AND pid <> pg_backend_pid()");
        $pdo->exec("DROP DATABASE \"{$testDbName}\"");
    }

    // Criar banco de teste
    $pdo->exec("CREATE DATABASE \"{$testDbName}\"");
    echo "Banco de dados '{$testDbName}' criado com sucesso.\n";

    // Conectar ao banco de teste
    $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$testDbName}";
    $testPdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Executar schema
    $schemaFile = dirname(__DIR__) . '/database/init.sql';
    if (file_exists($schemaFile)) {
        $sql = file_get_contents($schemaFile);
        $testPdo->exec($sql);
        echo "Schema aplicado com sucesso.\n";
    } else {
        echo "Arquivo de schema não encontrado: {$schemaFile}\n";
    }

    // Aplicar migrations
    $migrationsDir = dirname(__DIR__) . '/database/migrations';
    if (is_dir($migrationsDir)) {
        $migrations = glob($migrationsDir . '/*.sql');
        sort($migrations);
        foreach ($migrations as $migration) {
            $name = basename($migration);
            echo "Aplicando migration: {$name}...\n";
            try {
                $testPdo->exec(file_get_contents($migration));
                echo "  OK\n";
            } catch (PDOException $e) {
                echo "  Aviso: " . $e->getMessage() . "\n";
            }
        }
    }

    echo "\n=== Setup concluído! ===\n";

} catch (PDOException $e) {
    echo "Erro: " . $e->getMessage() . "\n";
    exit(1);
}
