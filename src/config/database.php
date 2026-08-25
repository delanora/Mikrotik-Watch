<?php

declare(strict_types=1);

/**
 * Mikrotik Watch - Conexão com Banco de Dados
 *
 * Configura e fornece a conex PDO com PostgreSQL.
 */

use PDO;
use PDOException;

/**
 * Obtém uma instância PDO conectada ao PostgreSQL.
 *
 * @param array $config Configurações do banco (host, port, name, user, password)
 * @return PDO
 * @throws RuntimeException Se a conexão falhar
 */
function getDatabase(array $config): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        $config['host'],
        $config['port'],
        $config['name']
    );

    try {
        $pdo = new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException(
            'Falha na conexão com o banco de dados: ' . $e->getMessage(),
            (int) $e->getCode(),
            $e
        );
    }

    return $pdo;
}

/**
 * Fecha a conexão com o banco de dados.
 */
function closeDatabase(): void
{
    // PDO é destruído automaticamente quando a variável sai de escopo
    // Esta função é fornecida para uso explícito quando necessário
}
