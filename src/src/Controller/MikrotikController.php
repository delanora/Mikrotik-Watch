<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Mikrotik Watch - Mikrotik Controller
 *
 * Gerenciamento dos equipamentos Mikrotik RouterOS.
 */
class MikrotikController
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function index(): void { echo "Mikrotik List - Em desenvolvimento"; }
    public function create(): void { echo "Mikrotik Create Form - Em desenvolvimento"; }
    public function store(): void { echo "Mikrotik Store - Em desenvolvimento"; }
    public function show(): void { echo "Mikrotik Show - Em desenvolvimento"; }
    public function edit(): void { echo "Mikrotik Edit - Em desenvolvimento"; }
    public function update(): void { echo "Mikrotik Update - Em desenvolvimento"; }
    public function delete(): void { echo "Mikrotik Delete - Em desenvolvimento"; }
    public function testConnection(): void { echo "Mikrotik Test Connection - Em desenvolvimento"; }
}
