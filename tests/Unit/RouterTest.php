<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Router;
use PHPUnit\Framework\TestCase;

/**
 * Mikrotik Watch - Router Tests
 */
class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testAddRoute(): void
    {
        $this->router->addRoute('GET', '/test', 'TestController@test');

        $routes = $this->router->getRoutes();
        $this->assertCount(1, $routes);
        $this->assertEquals('GET', $routes[0]['method']);
        $this->assertEquals('/test', $routes[0]['pattern']);
        $this->assertEquals('TestController@test', $routes[0]['handler']);
    }

    public function testLoadRoutes(): void
    {
        $routes = [
            ['GET', '/home', 'HomeController@index'],
            ['POST', '/login', 'AuthController@login'],
        ];

        $this->router->loadRoutes($routes);

        $loadedRoutes = $this->router->getRoutes();
        $this->assertCount(2, $loadedRoutes);
    }

    public function testResolveExactMatch(): void
    {
        $this->router->addRoute('GET', '/dashboard', 'DashboardController@index');

        $result = $this->router->resolve('GET', '/dashboard');
        $this->assertEquals('DashboardController@index', $result);
    }

    public function testResolveWithParameters(): void
    {
        $this->router->addRoute('GET', '/mikrotiks/{id}', 'MikrotikController@show');

        $result = $this->router->resolve('GET', '/mikrotiks/abc-123');
        $this->assertEquals('MikrotikController@show', $result);
    }

    public function testResolveNoMatch(): void
    {
        $this->router->addRoute('GET', '/test', 'TestController@test');

        $result = $this->router->resolve('GET', '/nonexistent');
        $this->assertNull($result);
    }

    public function testResolveMethodMismatch(): void
    {
        $this->router->addRoute('GET', '/test', 'TestController@test');

        $result = $this->router->resolve('POST', '/test');
        $this->assertNull($result);
    }

    public function testResolveCaseInsensitiveMethod(): void
    {
        $this->router->addRoute('get', '/test', 'TestController@test');

        $result = $this->router->resolve('GET', '/test');
        $this->assertEquals('TestController@test', $result);
    }

    public function testResolveTrailingSlash(): void
    {
        $this->router->addRoute('GET', '/', 'HomeController@index');

        $result = $this->router->resolve('GET', '/');
        $this->assertEquals('HomeController@index', $result);
    }
}
