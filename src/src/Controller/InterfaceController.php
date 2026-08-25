<?php

declare(strict_types=1);

namespace App\Controller;

class InterfaceController
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function index(): void { echo "Interfaces - Em desenvolvimento"; }
    public function trafficData(): void { echo "Traffic Data - Em desenvolvimento"; }
}
