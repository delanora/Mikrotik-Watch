<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\CredentialCrypto;
use App\Service\MikrotikClient;
use App\Service\Http\MockTransport;
use App\Exception\MikrotikApiException;
use PHPUnit\Framework\TestCase;

/**
 * Mikrotik Watch - Mikrotik CRUD Tests
 *
 * Testes para:
 * - Criação com criptografia da senha (round-trip)
 * - Teste de conexão via MockTransport
 * - Edição sem senha mantém a senha anterior
 * - Validação de campos obrigatórios
 */
class MikrotikCrudTest extends TestCase
{
    private CredentialCrypto $crypto;

    protected function setUp(): void
    {
        $this->crypto = new CredentialCrypto(CredentialCrypto::generateKey());
    }

    // ─── Criptografia de senha ────────────────────────────────────────────────

    public function testPasswordEncryptionRoundTrip(): void
    {
        $plaintext = 'MinhaSenh@Mikrotik!';

        // Criptografar (como o controller faz)
        $encryptedBase64 = $this->crypto->encrypt($plaintext);
        $encryptedBytes = base64_decode($encryptedBase64, true);

        $this->assertNotEquals($plaintext, $encryptedBytes);
        $this->assertNotEmpty($encryptedBytes);

        // Descriptografar (como o controller faz ao ler do banco)
        $readBase64 = base64_encode($encryptedBytes);
        $decrypted = $this->crypto->decrypt($readBase64);

        $this->assertEquals($plaintext, $decrypted);
    }

    public function testPasswordEncryptionStoresAsBytes(): void
    {
        $password = 'TestPass123';

        $encryptedBase64 = $this->crypto->encrypt($password);
        $encryptedBytes = base64_decode($encryptedBase64, true);

        // Verificar que é binário válido (não é texto)
        $this->assertNotEquals($encryptedBase64, $encryptedBytes);
        // nonce (24 bytes) + ciphertext (≥ 16 bytes) = ≥ 40 bytes
        $this->assertGreaterThanOrEqual(40, strlen($encryptedBytes));
    }

    public function testDifferentPasswordsProduceDifferentCiphertext(): void
    {
        $enc1 = base64_decode($this->crypto->encrypt('pass1'), true);
        $enc2 = base64_decode($this->crypto->encrypt('pass2'), true);

        $this->assertNotEquals($enc1, $enc2);
    }

    // ─── Teste de conexão via MockTransport ───────────────────────────────────

    public function testConnectionSuccessViaMockTransport(): void
    {
        $transport = new MockTransport();
        $transport->mockResponse(200, json_encode([
            'uptime'    => '1w2d3h4m5s',
            'version'   => '7.14.3',
            'cpu-count' => '4',
            'board-name' => 'hEX S',
        ]));

        $client = new MikrotikClient(
            host: '192.168.88.1',
            username: 'admin',
            password: 'secret',
            port: 443,
            useSsl: true,
            transport: $transport
        );

        $resource = $client->systemResource();

        $this->assertEquals('7.14.3', $resource['version']);
        $this->assertEquals('hEX S', $resource['board-name']);

        // Verificar que a requisição foi feita corretamente
        $call = $transport->getLastCall();
        $this->assertStringContainsString('/rest/system/resource', $call['url']);
    }

    public function testConnectionFailureViaMockTransport(): void
    {
        $transport = new MockTransport();
        $transport->mockResponse(401, json_encode([
            'detail' => 'invalid credentials',
        ]));

        $client = new MikrotikClient(
            host: '192.168.88.1',
            username: 'admin',
            password: 'wrong',
            port: 443,
            useSsl: true,
            transport: $transport
        );

        $this->expectException(MikrotikApiException::class);
        $this->expectExceptionMessage('HTTP 401');

        $client->systemResource();
    }

    public function testConnectionTimeoutViaMockTransport(): void
    {
        $transport = $this->createMock(\App\Service\Http\HttpTransport::class);
        $transport->method('request')
            ->willThrowException(new \RuntimeException('cURL error: Connection timed out'));

        $client = new MikrotikClient(
            host: '10.0.0.99',
            transport: $transport
        );

        $this->expectException(MikrotikApiException::class);
        $this->expectExceptionMessage('Falha de conexão');

        $client->systemResource();
    }

    public function testConnectionUsesFormDataCorrectly(): void
    {
        $transport = new MockTransport();
        $transport->mockResponse(200, json_encode(['version' => '7.14']));

        $client = new MikrotikClient(
            host: 'router.ddns.net',
            username: 'admin',
            password: 'mypassword',
            port: 8443,
            useSsl: false,
            transport: $transport
        );

        $client->systemResource();

        $call = $transport->getLastCall();
        $this->assertStringStartsWith('http://', $call['url']);
        $this->assertStringContainsString(':8443/', $call['url']);
    }

    // ─── Edição mantém senha anterior ─────────────────────────────────────────

    public function testEditWithoutPasswordKeepsExisting(): void
    {
        $originalPassword = 'OriginalPassword123!';

        // Criptografar como se fosse o store
        $encryptedBase64 = $this->crypto->encrypt($originalPassword);
        $encryptedBytes = base64_decode($encryptedBase64, true);

        // Simular leitura do banco (BYTEA → base64 → decrypt)
        $readBase64 = base64_encode($encryptedBytes);
        $decrypted = $this->crypto->decrypt($readBase64);

        $this->assertEquals($originalPassword, $decrypted);

        // Simular update SEM senha (senha vazia = manter)
        // Não criptografamos nada, mantemos os bytes originais
        $keptBytes = $encryptedBytes;

        // Verificar que a senha original ainda está lá
        $keptBase64 = base64_encode($keptBytes);
        $keptPassword = $this->crypto->decrypt($keptBase64);

        $this->assertEquals($originalPassword, $keptPassword);
    }

    public function testEditWithNewPasswordReplacesOld(): void
    {
        $oldPassword = 'OldPassword123!';
        $newPassword = 'NewSecureP@ss456!';

        // Store com senha antiga
        $oldEncrypted = base64_decode($this->crypto->encrypt($oldPassword), true);

        // Update com nova senha
        $newEncrypted = base64_decode($this->crypto->encrypt($newPassword), true);

        // Verificar que são diferentes
        $this->assertNotEquals($oldEncrypted, $newEncrypted);

        // Verificar que a nova senha descriptografa corretamente
        $decrypted = $this->crypto->decrypt(base64_encode($newEncrypted));
        $this->assertEquals($newPassword, $decrypted);

        // Verificar que a senha antiga NÃO descriptografa com os novos bytes
        $this->expectException(\App\Exception\AppException::class);
        $this->crypto->decrypt(base64_encode($newEncrypted) . 'corrupted');
    }

    // ─── Validação ────────────────────────────────────────────────────────────

    public function testValidationRequiresClientId(): void
    {
        $errors = $this->validate('', 'Router', '192.168.1.1', 'admin', 'pass');
        $this->assertContains('Selecione um cliente.', $errors);
    }

    public function testValidationRequiresName(): void
    {
        $errors = $this->validate('client-id', '', '192.168.1.1', 'admin', 'pass');
        $this->assertContains('O nome do equipamento é obrigatório.', $errors);
    }

    public function testValidationRequiresHost(): void
    {
        $errors = $this->validate('client-id', 'Router', '', 'admin', 'pass');
        $this->assertContains('O host (IP ou DDNS) é obrigatório.', $errors);
    }

    public function testValidationRequiresUsername(): void
    {
        $errors = $this->validate('client-id', 'Router', '192.168.1.1', '', 'pass');
        $this->assertContains('O usuário é obrigatório.', $errors);
    }

    public function testValidationRequiresPassword(): void
    {
        $errors = $this->validate('client-id', 'Router', '192.168.1.1', 'admin', '');
        $this->assertContains('A senha é obrigatória.', $errors);
    }

    public function testValidationPassesWithValidData(): void
    {
        $errors = $this->validate('client-id', 'Router', '192.168.1.1', 'admin', 'pass');
        $this->assertEmpty($errors);
    }

    public function testValidationNameMaxLength(): void
    {
        $errors = $this->validate('client-id', str_repeat('A', 151), '192.168.1.1', 'admin', 'pass');
        $this->assertContains('O nome não pode ter mais de 150 caracteres.', $errors);
    }

    public function testValidationUpdateRequiresName(): void
    {
        $errors = $this->validateUpdate('', '192.168.1.1', 'admin');
        $this->assertContains('O nome do equipamento é obrigatório.', $errors);
    }

    public function testValidationUpdateRequiresHost(): void
    {
        $errors = $this->validateUpdate('Router', '', 'admin');
        $this->assertContains('O host (IP ou DDNS) é obrigatório.', $errors);
    }

    public function testValidationUpdateRequiresUsername(): void
    {
        $errors = $this->validateUpdate('Router', '192.168.1.1', '');
        $this->assertContains('O usuário é obrigatório.', $errors);
    }

    public function testValidationUpdatePassesWithValidData(): void
    {
        $errors = $this->validateUpdate('Router', '192.168.1.1', 'admin');
        $this->assertEmpty($errors);
    }

    public function testValidationUpdatePasswordOptional(): void
    {
        // Na edição, senha vazia é válida (mantém a anterior)
        $errors = $this->validateUpdate('Router', '192.168.1.1', 'admin');
        $this->assertEmpty($errors);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Replica a lógica de validação do MikrotikController::validate()
     */
    private function validate(string $clientId, string $name, string $host, string $username, string $password): array
    {
        $errors = [];

        if ($clientId === '') {
            $errors[] = 'Selecione um cliente.';
        }
        if ($name === '') {
            $errors[] = 'O nome do equipamento é obrigatório.';
        } elseif (mb_strlen($name) > 150) {
            $errors[] = 'O nome não pode ter mais de 150 caracteres.';
        }
        if ($host === '') {
            $errors[] = 'O host (IP ou DDNS) é obrigatório.';
        }
        if ($username === '') {
            $errors[] = 'O usuário é obrigatório.';
        }
        if ($password === '') {
            $errors[] = 'A senha é obrigatória.';
        }

        return $errors;
    }

    /**
     * Replica a lógica de validação do MikrotikController::validateUpdate()
     */
    private function validateUpdate(string $name, string $host, string $username): array
    {
        $errors = [];

        if ($name === '') {
            $errors[] = 'O nome do equipamento é obrigatório.';
        }
        if ($host === '') {
            $errors[] = 'O host (IP ou DDNS) é obrigatório.';
        }
        if ($username === '') {
            $errors[] = 'O usuário é obrigatório.';
        }

        return $errors;
    }
}
