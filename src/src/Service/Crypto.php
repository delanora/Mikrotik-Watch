<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Mikrotik Watch - Password Hashing Service
 *
 * Serviço de hashing de senhas de usuário via bcrypt.
 * Para criptografia reversível de senhas Mikrotik, usar CredentialCrypto.
 */
class Crypto
{
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
