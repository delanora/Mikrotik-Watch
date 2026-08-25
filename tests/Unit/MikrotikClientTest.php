<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\MikrotikApiException;
use App\Service\MikrotikClient;
use App\Service\Http\MockTransport;
use PHPUnit\Framework\TestCase;

/**
 * Mikrotik Watch - MikrotikClient Tests
 *
 * Testes unitários para o cliente API REST do Mikrotik.
 * Usa MockTransport para não depender de um Mikrotik real.
 */
class MikrotikClientTest extends TestCase
{
    private MockTransport $transport;
    private MikrotikClient $client;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
        $this->client = new MikrotikClient(
            host: '192.168.88.1',
            username: 'admin',
            password: 'secret',
            port: 443,
            useSsl: true,
            verifySsl: true,
            timeout: 5,
            transport: $this->transport
        );
    }

    // ─── GET ──────────────────────────────────────────────────────────────────

    public function testGetReturnsDecodedJson(): void
    {
        $this->transport->mockResponse(200, json_encode([
            ['id' => '*1', 'name' => 'ether1', 'type' => 'ether'],
            ['id' => '*2', 'name' => 'wlan1', 'type' => 'wlan'],
        ]));

        $result = $this->client->get('/rest/interface');

        $this->assertCount(2, $result);
        $this->assertEquals('ether1', $result[0]['name']);
        $this->assertEquals('wlan1', $result[1]['name']);
    }

    public function testGetUsesCorrectUrl(): void
    {
        $this->transport->mockResponse(200, '{}');

        $this->client->get('/rest/system/resource');

        $call = $this->transport->getLastCall();
        $this->assertEquals('GET', $call['method']);
        $this->assertEquals('https://192.168.88.1:443/rest/system/resource', $call['url']);
    }

    public function testGetSendsJsonHeaders(): void
    {
        $this->transport->mockResponse(200, '{}');

        $this->client->get('/rest/system/resource');

        $call = $this->transport->getLastCall();
        $this->assertEquals('application/json', $call['headers']['Content-Type']);
        $this->assertEquals('application/json', $call['headers']['Accept']);
    }

    public function testGetEmptyResponseReturnsEmptyArray(): void
    {
        $this->transport->mockResponse(200, '');

        $result = $this->client->get('/rest/tool/netwatch');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetArrayResponseReturnsEmptyArray(): void
    {
        $this->transport->mockResponse(200, '[]');

        $result = $this->client->get('/rest/tool/netwatch');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ─── POST ─────────────────────────────────────────────────────────────────

    public function testPostSendsJsonBody(): void
    {
        $this->transport->mockResponse(200, '{}');

        $this->client->post('/rest/tool/netwatch', ['host' => '10.0.0.1', 'comment' => 'test']);

        $call = $this->transport->getLastCall();
        $this->assertEquals('POST', $call['method']);
        $decoded = json_decode($call['body'], true);
        $this->assertEquals('10.0.0.1', $decoded['host']);
        $this->assertEquals('test', $decoded['comment']);
    }

    // ─── PUT ──────────────────────────────────────────────────────────────────

    public function testPutSendsCorrectMethod(): void
    {
        $this->transport->mockResponse(200, '{}');

        $this->client->put('/rest/tool/netwatch/*1', ['comment' => 'updated']);

        $call = $this->transport->getLastCall();
        $this->assertEquals('PUT', $call['method']);
    }

    // ─── PATCH ────────────────────────────────────────────────────────────────

    public function testPatchSendsCorrectMethod(): void
    {
        $this->transport->mockResponse(200, '{}');

        $this->client->patch('/rest/tool/netwatch/*1', ['comment' => 'patched']);

        $call = $this->transport->getLastCall();
        $this->assertEquals('PATCH', $call['method']);
    }

    // ─── DELETE ───────────────────────────────────────────────────────────────

    public function testDeleteSendsCorrectMethod(): void
    {
        $this->transport->mockResponse(200, '{}');

        $this->client->delete('/rest/tool/netwatch/*1');

        $call = $this->transport->getLastCall();
        $this->assertEquals('DELETE', $call['method']);
        $this->assertNull($call['body']);
    }

    // ─── Conveniência ─────────────────────────────────────────────────────────

    public function testSystemResource(): void
    {
        $this->transport->mockResponse(200, json_encode([
            'uptime'    => '1w2d3h4m5s',
            'version'   => '7.14.3',
            'cpu-count' => '4',
            'total-memory' => '268435456',
        ]));

        $result = $this->client->systemResource();

        $this->assertEquals('7.14.3', $result['version']);
        $this->assertEquals('4', $result['cpu-count']);

        $call = $this->transport->getLastCall();
        $this->assertStringContainsString('/rest/system/resource', $call['url']);
    }

    public function testSystemHealth(): void
    {
        $this->transport->mockResponse(200, json_encode([
            ['name' => 'temperature', 'value' => '42'],
            ['name' => 'voltage', 'value' => '24.1'],
        ]));

        $result = $this->client->systemHealth();

        $this->assertCount(2, $result);

        $call = $this->transport->getLastCall();
        $this->assertStringContainsString('/rest/system/health', $call['url']);
    }

    public function testNetwatch(): void
    {
        $this->transport->mockResponse(200, json_encode([
            ['id' => '*1', 'host' => '10.0.0.1', 'status' => 'up'],
            ['id' => '*2', 'host' => '10.0.0.2', 'status' => 'down'],
        ]));

        $result = $this->client->netwatch();

        $this->assertCount(2, $result);
        $this->assertEquals('up', $result[0]['status']);
        $this->assertEquals('down', $result[1]['status']);

        $call = $this->transport->getLastCall();
        $this->assertStringContainsString('/rest/tool/netwatch', $call['url']);
    }

    public function testInterfaces(): void
    {
        $this->transport->mockResponse(200, json_encode([
            ['id' => '*1', 'name' => 'ether1', 'type' => 'ether', 'running' => true],
        ]));

        $result = $this->client->interfaces();

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['running']);
    }

    public function testSystemInfo(): void
    {
        $this->transport->mockResponse(200, json_encode([
            'identity' => 'MikroTik-Router',
            'version'  => '7.14.3',
        ]));

        $result = $this->client->systemInfo();

        $this->assertEquals('MikroTik-Router', $result['identity']);
    }

    // ─── Erros ────────────────────────────────────────────────────────────────

    public function testThrowsMikrotikApiExceptionOn401(): void
    {
        $this->transport->mockResponse(401, json_encode([
            'detail' => 'invalid credentials',
        ]));

        $this->expectException(MikrotikApiException::class);
        $this->expectExceptionMessage('HTTP 401');
        $this->expectExceptionMessage('invalid credentials');

        $this->client->get('/rest/system/resource');
    }

    public function testThrowsMikrotikApiExceptionOn404(): void
    {
        $this->transport->mockResponse(404, '');

        $this->expectException(MikrotikApiException::class);
        $this->expectExceptionCode(404);

        $this->client->get('/rest/nonexistent');
    }

    public function testThrowsMikrotikApiExceptionOn500(): void
    {
        $this->transport->mockResponse(500, 'Internal Server Error');

        $this->expectException(MikrotikApiException::class);
        $this->expectExceptionCode(500);

        $this->client->get('/rest/system/resource');
    }

    public function testExceptionContainsHostAndEndpoint(): void
    {
        $this->transport->mockResponse(403, '');

        try {
            $this->client->get('/rest/system/resource');
            $this->fail('Expected MikrotikApiException');
        } catch (MikrotikApiException $e) {
            $this->assertEquals('192.168.88.1', $e->getHost());
            $this->assertEquals('/rest/system/resource', $e->getEndpoint());
            $this->assertEquals(403, $e->getHttpStatus());
        }
    }

    public function testThrowsOnConnectionFailure(): void
    {
        $transport = $this->createMock(\App\Service\Http\HttpTransport::class);
        $transport->method('request')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $client = new MikrotikClient(
            host: '192.168.88.99',
            transport: $transport
        );

        $this->expectException(MikrotikApiException::class);
        $this->expectExceptionMessage('Falha de conexão');

        $client->get('/rest/system/resource');
    }

    // ─── SSL e configuração ───────────────────────────────────────────────────

    public function testUsesHttpWhenSslDisabled(): void
    {
        $client = new MikrotikClient(
            host: '192.168.88.1',
            useSsl: false,
            transport: $this->transport
        );

        $this->transport->mockResponse(200, '{}');

        $client->get('/rest/system/resource');

        $call = $this->transport->getLastCall();
        $this->assertStringStartsWith('http://', $call['url']);
    }

    public function testUsesHttpsWhenSslEnabled(): void
    {
        $this->transport->mockResponse(200, '{}');

        $this->client->get('/rest/system/resource');

        $call = $this->transport->getLastCall();
        $this->assertStringStartsWith('https://', $call['url']);
    }

    public function testUsesCustomPort(): void
    {
        $client = new MikrotikClient(
            host: '192.168.88.1',
            port: 8443,
            transport: $this->transport
        );

        $this->transport->mockResponse(200, '{}');

        $client->get('/rest/system/resource');

        $call = $this->transport->getLastCall();
        $this->assertStringContainsString(':8443/', $call['url']);
    }

    // ─── Getters ──────────────────────────────────────────────────────────────

    public function testGetters(): void
    {
        $this->assertEquals('192.168.88.1', $this->client->getHost());
        $this->assertEquals(443, $this->client->getPort());
        $this->assertEquals('admin', $this->client->getUsername());
        $this->assertEquals(5, $this->client->getTimeout());
    }
}
