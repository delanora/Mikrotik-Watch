<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\AppException;

/**
 * Mikrotik Watch - Credential Crypto Service
 *
 * Criptografia reversível das senhas dos Mikrotiks usando libsodium
 * (sodium_crypto_secretbox, nativo do PHP 8.4).
 *
 * A chave de 32 bytes vem da variável de ambiente CREDENTIAL_ENCRYPTION_KEY,
 * NUNCA fica hardcoded ou no banco de dados.
 *
 * Formato do ciphertext: nonce (24 bytes) + ciphertext, codificado em base64.
 */
class CredentialCrypto
{
    private string $key;

    /**
     * @param string $key Chave de 32 bytes em base64 (vem de CREDENTIAL_ENCRYPTION_KEY)
     * @throws AppException Se a chave não for válida
     */
    public function __construct(string $key)
    {
        $decoded = base64_decode($key, true);

        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new AppException(
                'Chave de criptografia inválida. Use: php -r "echo base64_encode(random_bytes(32));"'
            );
        }

        $this->key = $decoded;
    }

    /**
     * Criptografa um texto plano.
     *
     * @param string $plaintext Texto a ser criptografado
     * @return string Base64 do nonce + ciphertext
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $ciphertext);
    }

    /**
     * Descriptografa um texto criptografado.
     *
     * @param string $encrypted Base64 do nonce + ciphertext
     * @return string Texto original
     * @throws AppException Se a decriptação falhar (chave incorreta ou dado corrompido)
     */
    public function decrypt(string $encrypted): string
    {
        $decoded = base64_decode($encrypted, true);

        if ($decoded === false) {
            throw new AppException('Dado criptografado inválido.');
        }

        $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

        if (strlen($decoded) < $nonceLength) {
            throw new AppException('Dado criptografado corrompido.');
        }

        $nonce = substr($decoded, 0, $nonceLength);
        $ciphertext = substr($decoded, $nonceLength);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext === false) {
            throw new AppException('Falha ao decriptar: chave incorreta ou dado corrompido.');
        }

        return $plaintext;
    }

    /**
     * Gera uma nova chave de criptografia (para uso no setup inicial).
     *
     * @return string Chave codificada em base64
     */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }
}
