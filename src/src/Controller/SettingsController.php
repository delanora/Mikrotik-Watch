<?php

declare(strict_types=1);

namespace App\Controller;

class SettingsController
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function index(): void { echo "Settings - Em desenvolvimento"; }
    public function update(): void { echo "Settings Update - Em desenvolvimento"; }
}
