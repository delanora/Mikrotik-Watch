<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Mikrotik Watch - Crypto Service
 *
 * Serviço de criptografia para dados sensíveis (senhas de API Mikrotik).
 */
class Crypto
{
    /**
     * Criptografa um valor usando a chave secreta da aplicação.
     *
     * @param string $value
     * @param string $secret
     * @return string
     */
    public static function encrypt(string $value, string $secret): string
    {
        // TODO: Implementar criptografia com openssl
        return $value;
    }

    /**
     * Descriptografa um valor criptografado.
     *
     * @param string $encrypted
     * @param string $secret
     * @return string
     */
    public static function decrypt(string $encrypted, string $secret): string
    {
        // TODO: Implementar descriptografia com openssl
        return $encrypted;
    }

    /**
     * Gera um hash bcrypt para senhas de usuário.
     *
     * @param string $password
     * @return string
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Verifica uma senha contra um hash bcrypt.
     *
     * @param string $password
     * @param string $hash
     * @return bool
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
