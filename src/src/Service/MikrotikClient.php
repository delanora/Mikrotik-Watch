<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Mikrotik Watch - Mikrotik API Client
 *
 * Serviço para comunicação com equipamentos Mikrotik via RouterOS API.
 * TODO: Implementar conexão via API TCP/IP do RouterOS.
 */
class MikrotikClient
{
    private string $host;
    private int $port;
    private string $user;
    private string $password;
    private int $timeout;

    public function __construct(
        string $host,
        int $port = 8728,
        string $user = 'admin',
        string $password = '',
        int $timeout = 10
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->password = $password;
        $this->timeout = $timeout;
    }

    /**
     * Testa a conexão com o equipamento.
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        // TODO: Implementar teste de conexão via RouterOS API
        return false;
    }

    /**
     * Executa um comando no RouterOS.
     *
     * @param string $command
     * @return array
     */
    public function command(string $command): array
    {
        // TODO: Implementar execução de comandos via API
        return [];
    }

    /**
     * Obtém informações básicas do equipamento.
     *
     * @return array
     */
    public function getSystemInfo(): array
    {
        // TODO: Implementar /system/resource/print
        return [];
    }

    /**
     * Obtém lista de interfaces.
     *
     * @return array
     */
    public function getInterfaces(): array
    {
        // TODO: Implementar /interface/print
        return [];
    }

    /**
     * Obtém tráfego de uma interface.
     *
     * @param string $interfaceName
     * @return array
     */
    public function getInterfaceTraffic(string $interfaceName): array
    {
        // TODO: Implementar /interface/monitor
        return [];
    }
}
