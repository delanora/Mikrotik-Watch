<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Service\MikrotikClient;

/**
 * Testes unitários para o mecanismo de requisições em paralelo (batchGet/batchRequest).
 */
class BatchRequestTest extends TestCase
{
    /**
     * Testa que batchGet retorna array vazio quando não há requisições.
     */
    public function testBatchGetWithEmptyRequests(): void
    {
        $results = MikrotikClient::batchGet([], timeout: 5);
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    /**
     * Testa que um host inacessível não bloqueia os demais.
     * Usa curl_multi com um host não roteável (RFC 5737) e verifica que
     * o timeout individual não impede processamento de outros.
     */
    public function testUnreachableHostDoesNotBlockOthers(): void
    {
        $requests = [
            [
                'mikrotik_id' => 'offline_device',
                'key'         => 'offline_resource',
                'endpoint'    => '/rest/system/resource',
                'host'        => '192.0.2.1', // RFC 5737 TEST-NET (não roteável)
                'port'        => 80,
                'use_ssl'     => false,
                'username'    => 'admin',
                'password'    => 'test',
            ],
            [
                'mikrotik_id' => 'another_offline',
                'key'         => 'another_resource',
                'endpoint'    => '/rest/system/resource',
                'host'        => '192.0.2.2', // RFC 5737 TEST-NET (não roteável)
                'port'        => 80,
                'use_ssl'     => false,
                'username'    => 'admin',
                'password'    => 'test',
            ],
        ];

        $startTime = microtime(true);
        $results = MikrotikClient::batchGet($requests, timeout: 2, verifySsl: false, maxConcurrency: 10);
        $elapsed = microtime(true) - $startTime;

        // Ambos os resultados devem estar presentes com erro
        $this->assertCount(2, $results, 'batchGet deve retornar resultado para cada requisição');
        $this->assertArrayHasKey('offline_resource', $results);
        $this->assertArrayHasKey('another_resource', $results);

        // Ambos devem ter erro (host inacessível)
        $this->assertArrayHasKey('error', $results['offline_resource']);
        $this->assertArrayHasKey('error', $results['another_resource']);

        // Tempo total deve ser ~2s (timeout), não ~4s (2 hosts × 2s cada sequencial)
        // Isso prova que as requisições foram feitas em paralelo
        $this->assertLessThan(3.5, $elapsed,
            'As requisições devem ser feitas em paralelo (timeout total ~2s, não ~4s sequencial)'
        );
    }

    /**
     * Testa que múltiplas requisições para o mesmo mikrotik_id com keys diferentes
     * não se sobrescrevem (bug anterior: $handles[$id] sobrescrevia handles duplicados).
     */
    public function testMultipleRequestsForSameDeviceWithDifferentKeys(): void
    {
        // Dois requests para o mesmo mikrotik_id mas com keys diferentes
        // Ambos vão falhar (host inacessível), mas devem retornar独立的结果
        $requests = [
            [
                'mikrotik_id' => 'shared_device',
                'key'         => 'shared_resource',
                'endpoint'    => '/rest/system/resource',
                'host'        => '192.0.2.1',
                'port'        => 80,
                'use_ssl'     => false,
                'username'    => 'admin',
                'password'    => 'test',
            ],
            [
                'mikrotik_id' => 'shared_device',
                'key'         => 'shared_health',
                'endpoint'    => '/rest/system/health',
                'host'        => '192.0.2.1',
                'port'        => 80,
                'use_ssl'     => false,
                'username'    => 'admin',
                'password'    => 'test',
            ],
        ];

        $results = MikrotikClient::batchGet($requests, timeout: 2, verifySsl: false, maxConcurrency: 10);

        // Ambas as keys devem estar presentes (não sobrescrever)
        $this->assertCount(2, $results, 'Múltiplos requests para o mesmo device devem ter keys independentes');
        $this->assertArrayHasKey('shared_resource', $results);
        $this->assertArrayHasKey('shared_health', $results);

        // Ambos devem ter erro (host inacessível)
        $this->assertArrayHasKey('error', $results['shared_resource']);
        $this->assertArrayHasKey('error', $results['shared_health']);
    }
}
