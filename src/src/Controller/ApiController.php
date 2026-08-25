<?php

declare(strict_types=1);

namespace App\Controller;

class ApiController
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function stats(): void { echo "API Stats - Em desenvolvimento"; }
    public function mikrotiks(): void { echo "API Mikrotiks - Em desenvolvimento"; }
    public function trafficData(): void { echo "API Traffic - Em desenvolvimento"; }
}
