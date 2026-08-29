<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Mikrotik Watch - Pagination Integration Tests
 *
 * Testa a lógica de paginação usada por ClientController, MikrotikController
 * e ClientHostsController, validando LIMIT/OFFSET, valores inválidos e filtros.
 */
class PaginationTest extends TestCase
{
    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = self::getTestDatabase();
        self::truncateTables();
    }

    protected function setUp(): void
    {
        self::truncateTables();
    }

    // ─── CLIENT PAGINATION ────────────────────────────────────────────────────

    public function testClientPaginationReturnsCorrectRecords(): void
    {
        // Criar 25 clientes
        for ($i = 1; $i <= 25; $i++) {
            self::createClient("Cliente {$i}");
        }

        // Página 1: 10 itens (ordenados lexicograficamente)
        $stmt = self::$pdo->prepare('SELECT name FROM clients ORDER BY name ASC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', 10, PDO::PARAM_INT);
        $stmt->bindValue(':offset', 0, PDO::PARAM_INT);
        $stmt->execute();
        $page1 = $stmt->fetchAll();

        $this->assertCount(10, $page1, 'Página 1 deve ter 10 registros');

        // Página 2: próximos 10 (sem sobreposição com página 1)
        $stmt = self::$pdo->prepare('SELECT name FROM clients ORDER BY name ASC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', 10, PDO::PARAM_INT);
        $stmt->bindValue(':offset', 10, PDO::PARAM_INT);
        $stmt->execute();
        $page2 = $stmt->fetchAll();

        $this->assertCount(10, $page2, 'Página 2 deve ter 10 registros');

        // Nenhum registro repetido entre páginas
        $page1Names = array_column($page1, 'name');
        $page2Names = array_column($page2, 'name');
        $this->assertEmpty(array_intersect($page1Names, $page2Names), 'Páginas 1 e 2 não devem ter registros em comum');

        // Página 3: últimos 5
        $stmt = self::$pdo->prepare('SELECT name FROM clients ORDER BY name ASC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', 10, PDO::PARAM_INT);
        $stmt->bindValue(':offset', 20, PDO::PARAM_INT);
        $stmt->execute();
        $page3 = $stmt->fetchAll();

        $this->assertCount(5, $page3, 'Página 3 deve ter 5 registros');

        // Total de páginas
        $totalPages = (int) ceil(25 / 10);
        $this->assertEquals(3, $totalPages);
    }

    public function testInvalidPageValuesDefaultToPage1(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            self::createClient("Cliente {$i}");
        }

        $perPage = 10;

        // Testar page=0, page=-1, page=abc — todos devem resultar em page=1
        foreach ([0, -1, (int) 'abc'] as $invalidPage) {
            $page = max(1, $invalidPage); // Lógica dos controllers
            $offset = ($page - 1) * $perPage;

            $stmt = self::$pdo->prepare('SELECT name FROM clients ORDER BY name ASC LIMIT :limit OFFSET :offset');
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll();

            $this->assertNotEmpty($results, "Página inválida {$invalidPage} deve cair na página 1");
        }
    }

    public function testPageBeyondTotalReturnsLastPage(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            self::createClient("Cliente {$i}");
        }

        $perPage = 10;
        $totalRows = 15;
        $totalPages = (int) ceil($totalRows / $perPage); // = 2

        // Pedir página 999 — deve cair na página 2
        $requestedPage = 999;
        $page = min($requestedPage, $totalPages); // = 2
        $offset = ($page - 1) * $perPage;

        $stmt = self::$pdo->prepare('SELECT name FROM clients ORDER BY name ASC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll();

        $this->assertCount(5, $results, 'Página 999 com 2 páginas de dados deve retornar a última página');

        // Verificar que são os 5 últimos registros
        $allStmt = self::$pdo->query('SELECT name FROM clients ORDER BY name ASC');
        $allNames = array_column($allStmt->fetchAll(), 'name');
        $lastFive = array_slice($allNames, -5);

        $resultNames = array_column($results, 'name');
        $this->assertEquals($lastFive, $resultNames, 'Página além do total deve retornar exatamente os últimos registros');
    }

    // ─── MIKROTIK PAGINATION WITH FILTER ──────────────────────────────────────

    public function testMikrotikPaginationWithClientFilter(): void
    {
        $client1 = self::createClient('Filtrável A');
        $client2 = self::createClient('Filtrável B');

        // Cliente 1: 8 Mikrotiks
        for ($i = 1; $i <= 8; $i++) {
            self::createMikrotik($client1, "Mik-A-{$i}");
        }

        // Cliente 2: 15 Mikrotiks
        for ($i = 1; $i <= 15; $i++) {
            self::createMikrotik($client2, "Mik-B-{$i}");
        }

        $perPage = 10;

        // Filtro por cliente 1: 8 registros, 1 página
        $whereClause = 'WHERE m.active = true AND m.client_id = :client_id';
        $params = [':client_id' => $client1];

        $countSql = "SELECT COUNT(*) FROM mikrotiks m {$whereClause}";
        $countStmt = self::$pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalRows = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / $perPage));

        $this->assertEquals(8, $totalRows, 'Cliente 1 deve ter 8 Mikrotiks');
        $this->assertEquals(1, $totalPages, 'Cliente 1 deve ter 1 página');

        // Filtro por cliente 2: 15 registros, 2 páginas
        $params = [':client_id' => $client2];
        $countStmt = self::$pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalRows = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / $perPage));

        $this->assertEquals(15, $totalRows, 'Cliente 2 deve ter 15 Mikrotiks');
        $this->assertEquals(2, $totalPages, 'Cliente 2 deve ter 2 páginas');

        // Página 2 do cliente 2: 5 registros
        $page = 2;
        $offset = ($page - 1) * $perPage;

        $stmt = self::$pdo->prepare("
            SELECT m.name FROM mikrotiks m {$whereClause}
            ORDER BY m.name ASC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $page2 = $stmt->fetchAll();

        $this->assertCount(5, $page2, 'Página 2 do cliente 2 deve ter 5 registros');
    }

    // ─── HOSTS PAGINATION ─────────────────────────────────────────────────────

    public function testHostsPaginationPerClient(): void
    {
        $client = self::createClient('Host Client');
        $mik1 = self::createMikrotik($client, 'Mik-1');
        $mik2 = self::createMikrotik($client, 'Mik-2');

        // 25 hosts distribuídos entre 2 Mikrotiks
        for ($i = 1; $i <= 15; $i++) {
            self::createHost($mik1, "10.0.1.{$i}");
        }
        for ($i = 1; $i <= 10; $i++) {
            self::createHost($mik2, "10.0.2.{$i}");
        }

        // Buscar Mikrotiks do cliente
        $stmt = self::$pdo->prepare('SELECT id FROM mikrotiks WHERE client_id = :client_id AND active = true');
        $stmt->execute([':client_id' => $client]);
        $mikrotikIds = array_column($stmt->fetchAll(), 'id');
        $placeholders = implode(',', array_fill(0, count($mikrotikIds), '?'));

        // Total
        $countStmt = self::$pdo->prepare("SELECT COUNT(*) FROM netwatch_hosts nh WHERE nh.mikrotik_id IN ({$placeholders}) AND nh.active = true");
        foreach ($mikrotikIds as $i => $id) {
            $countStmt->bindValue($i + 1, $id, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $totalRows = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / 10));

        $this->assertEquals(25, $totalRows, 'Deve ter 25 hosts no total');
        $this->assertEquals(3, $totalPages, 'Deve ter 3 páginas de hosts');

        // Página 1: 10 hosts
        $stmt = self::$pdo->prepare("
            SELECT nh.host_address FROM netwatch_hosts nh
            WHERE nh.mikrotik_id IN ({$placeholders}) AND nh.active = true
            ORDER BY nh.host_address ASC
            LIMIT ? OFFSET ?
        ");
        $i = 1;
        foreach ($mikrotikIds as $id) {
            $stmt->bindValue($i++, $id, PDO::PARAM_STR);
        }
        $stmt->bindValue($i++, 10, PDO::PARAM_INT);
        $stmt->bindValue($i, 0, PDO::PARAM_INT);
        $stmt->execute();
        $page1 = $stmt->fetchAll();

        $this->assertCount(10, $page1, 'Página 1 de hosts deve ter 10 registros');
    }

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    private static function createClient(string $name): string
    {
        $stmt = self::$pdo->prepare('INSERT INTO clients (name) VALUES (:name) RETURNING id');
        $stmt->execute([':name' => $name]);
        return $stmt->fetch()['id'];
    }

    private static function createMikrotik(string $clientId, string $name): string
    {
        $stmt = self::$pdo->prepare('
            INSERT INTO mikrotiks (client_id, name, host, username, password_encrypted)
            VALUES (:client_id, :name, :host, :username, :pw)
            RETURNING id
        ');
        $stmt->execute([
            ':client_id' => $clientId,
            ':name'      => $name,
            ':host'      => '10.0.0.' . rand(1, 254),
            ':username'  => 'admin',
            ':pw'        => '\\x0000000000000000000000000000000000',
        ]);
        return $stmt->fetch()['id'];
    }

    private static function createHost(string $mikrotikId, string $address): string
    {
        $stmt = self::$pdo->prepare('INSERT INTO netwatch_hosts (mikrotik_id, host_address) VALUES (:id, :addr) RETURNING id');
        $stmt->execute([':id' => $mikrotikId, ':addr' => $address]);
        return $stmt->fetch()['id'];
    }

    private static function truncateTables(): void
    {
        self::$pdo->exec('
            TRUNCATE TABLE
                health_log_hourly, health_log_daily,
                netwatch_events, mikrotik_events,
                health_log, netwatch_hosts,
                mikrotiks, cron_locks, users, clients
            CASCADE
        ');
    }

    private static function getTestDatabase(): PDO
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('TEST_DB_PORT') ?: '5432';
        $name = getenv('TEST_DB_NAME') ?: 'mikrotik_watch_test';
        $user = getenv('TEST_DB_USER') ?: 'mikrotik_watch';
        $pass = getenv('TEST_DB_PASSWORD') ?: '';

        return new PDO("pgsql:host={$host};port={$port};dbname={$name}", $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
}
