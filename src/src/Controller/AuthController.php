<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Mikrotik Watch - Auth Controller
 *
 * Responsável por autenticação e controle de acesso.
 */
class AuthController
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Exibe o formulário de login.
     */
    public function loginForm(): void
    {
        // TODO: Implementar formulário de login
        echo "Login - Em desenvolvimento";
    }

    /**
     * Processa o login.
     */
    public function login(): void
    {
        // TODO: Implementar lógica de autenticação
        echo "Login processing - Em desenvolvimento";
    }

    /**
     * Encerra a sessão do usuário.
     */
    public function logout(): void
    {
        // TODO: Implementar logout
        echo "Logout - Em desenvolvimento";
    }
}
