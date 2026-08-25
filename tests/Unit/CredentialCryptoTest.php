<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\AppException;
use App\Service\CredentialCrypto;
use PHPUnit\Framework\TestCase;

/**
 * Mikrotik Watch - CredentialCrypto Tests
 *
 * Testes unitários para o serviço de criptografia reversível de senhas Mikrotik.
 */
class CredentialCryptoTest extends TestCase
{
    private CredentialCrypto $crypto;

    protected function setUp(): void
    {
        $this->crypto = new CredentialCrypto(CredentialCrypto::generateKey());
    }

    // ─── Round-trip encrypt/decrypt ───────────────────────────────────────────

    public function testEncryptDecryptRoundTrip(): void
    {
        $plaintext = 'MinhaSenh@123!';

        $encrypted = $this->crypto->encrypt($plaintext);

        $this->assertNotEquals($plaintext, $encrypted);

        $decrypted = $this->crypto->decrypt($encrypted);

        $this->assertEquals($plaintext, $decrypted);
    }

    public function testEncryptDecryptEmptyString(): void
    {
        $encrypted = $this->crypto->encrypt('');
        $decrypted = $this->crypto->decrypt($encrypted);

        $this->assertEquals('', $decrypted);
    }

    public function testEncryptDecryptSpecialCharacters(): void
    {
        $plaintext = 'p@$$w0rd!#%^&*()_+-={}[]|;:\'",.<>?/~`';

        $encrypted = $this->crypto->encrypt($plaintext);
        $decrypted = $this->crypto->decrypt($encrypted);

        $this->assertEquals($plaintext, $decrypted);
    }

    public function testEncryptDecryptUnicode(): void
    {
        $plaintext = 'SenhaComAcentos: áéíóú ñ ü ç ß 中文';

        $encrypted = $this->crypto->encrypt($plaintext);
        $decrypted = $this->crypto->decrypt($encrypted);

        $this->assertEquals($plaintext, $decrypted);
    }

    public function testEncryptDecryptLongString(): void
    {
        $plaintext = str_repeat('A', 10000);

        $encrypted = $this->crypto->encrypt($plaintext);
        $decrypted = $this->crypto->decrypt($encrypted);

        $this->assertEquals($plaintext, $decrypted);
    }

    public function testEncryptedOutputIsValidBase64(): void
    {
        $encrypted = $this->crypto->encrypt('test');

        $decoded = base64_decode($encrypted, true);
        $this->assertNotFalse($decoded);

        // nonce (24 bytes) + ciphertext (>= 16 bytes) = minimum 40 bytes
        $this->assertGreaterThanOrEqual(40, strlen($decoded));
    }

    public function testEachEncryptionProducesDifferentCiphertext(): void
    {
        $plaintext = 'same_password';

        $encrypted1 = $this->crypto->encrypt($plaintext);
        $encrypted2 = $this->crypto->encrypt($plaintext);

        // Nonces aleatórios geram ciphertexts diferentes
        $this->assertNotEquals($encrypted1, $encrypted2);

        // Mas ambos decriptam para o mesmo valor
        $this->assertEquals($plaintext, $this->crypto->decrypt($encrypted1));
        $this->assertEquals($plaintext, $this->crypto->decrypt($encrypted2));
    }

    // ─── Falha com chave errada ───────────────────────────────────────────────

    public function testDecryptWithWrongKeyThrowsException(): void
    {
        $crypto1 = new CredentialCrypto(CredentialCrypto::generateKey());
        $crypto2 = new CredentialCrypto(CredentialCrypto::generateKey());

        $encrypted = $crypto1->encrypt('secret_password');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('chave incorreta ou dado corrompido');

        $crypto2->decrypt($encrypted);
    }

    public function testDecryptCorruptedDataThrowsException(): void
    {
        $encrypted = $this->crypto->encrypt('test');

        // Corromper o dado trocando alguns caracteres no meio
        $corrupted = substr($encrypted, 0, 10) . 'XXXX' . substr($encrypted, 14);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('chave incorreta ou dado corrompido');

        $this->crypto->decrypt($corrupted);
    }

    public function testDecryptInvalidBase64ThrowsException(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('inválido');

        $this->crypto->decrypt('not-valid-base64!!!@#$%');
    }

    public function testDecryptTooShortDataThrowsException(): void
    {
        // Base64 de apenas 10 bytes (menor que o nonce de 24 bytes)
        $short = base64_encode('shortdata');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('corrompido');

        $this->crypto->decrypt($short);
    }

    // ─── Validação da chave ───────────────────────────────────────────────────

    public function testConstructorRejectsInvalidKey(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('inválida');

        new CredentialCrypto('not-a-valid-base64-key!!!');
    }

    public function testConstructorRejectsWrongLengthKey(): void
    {
        // Chave de 16 bytes (precisa ser 32)
        $shortKey = base64_encode(random_bytes(16));

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('inválida');

        new CredentialCrypto($shortKey);
    }

    public function testConstructorAcceptsValidKey(): void
    {
        $key = CredentialCrypto::generateKey();
        $crypto = new CredentialCrypto($key);

        $this->assertInstanceOf(CredentialCrypto::class, $crypto);
    }

    // ─── Generate key ─────────────────────────────────────────────────────────

    public function testGenerateKeyReturnsBase64Encoded32Bytes(): void
    {
        $key = CredentialCrypto::generateKey();

        $decoded = base64_decode($key, true);
        $this->assertNotFalse($decoded);
        $this->assertEquals(32, strlen($decoded));
    }

    public function testGenerateKeyIsRandom(): void
    {
        $key1 = CredentialCrypto::generateKey();
        $key2 = CredentialCrypto::generateKey();

        $this->assertNotEquals($key1, $key2);
    }

    // ─── Independência de instâncias ──────────────────────────────────────────

    public function testTwoInstancesWithSameKeyCanCommunicate(): void
    {
        $key = CredentialCrypto::generateKey();

        $crypto1 = new CredentialCrypto($key);
        $crypto2 = new CredentialCrypto($key);

        $encrypted = $crypto1->encrypt('shared_secret');
        $decrypted = $crypto2->decrypt($encrypted);

        $this->assertEquals('shared_secret', $decrypted);
    }
}
