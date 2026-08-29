<?php
/**
 * Mikrotik Watch - Seed de Dados para Desenvolvimento
 *
 * Popula o banco com clientes, equipamentos (Mikrotik + Ping),
 * hosts netwatch, métricas e eventos fictícios para desenvolvimento.
 *
 * Uso:
 *   cd /var/www/Mikrotik-Watch && php database/seed_dev.php
 *
 * ⚠️  SOMENTE PARA DESENVOLVIMENTO. Não execute em produção.
 *     Limpa dados existentes antes de inserir (exceto users e cron_locks).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$config = require __DIR__ . '/../src/config/config.php';

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║  Mikrotik Watch - Seed de Dados para Desenvolvimento    ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// ─── Conectar ao banco ──────────────────────────────────────────────────────

$dbCfg = $config['database'];
$dsn = "pgsql:host={$dbCfg['host']};port={$dbCfg['port']};dbname={$dbCfg['name']}";

try {
    $db = new PDO($dsn, $dbCfg['user'], $dbCfg['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    echo "ERRO: Falha ao conectar no banco: " . $e->getMessage() . "\n";
    exit(1);
}

echo "✓ Conectado ao banco {$dbCfg['name']}\n";

// ─── Crypto ─────────────────────────────────────────────────────────────────

$crypto = new \App\Service\CredentialCrypto($config['mikrotik']['credential_key']);

// ─── Limpar dados existentes (manter users e cron_locks) ───────────────────

echo "Limpando dados existentes...\n";

$db->exec('DELETE FROM netwatch_events');
$db->exec('DELETE FROM netwatch_hosts');
$db->exec('DELETE FROM mikrotik_events');
$db->exec('DELETE FROM health_log');
$db->exec('DELETE FROM mikrotiks');
$db->exec('DELETE FROM clients');

echo "✓ Dados antigos removidos\n";

// ─── Helpers ────────────────────────────────────────────────────────────────

function uuid(): string
{
    return bin2hex(random_bytes(16));
}

function randomPast(int $minMinutes, int $maxMinutes): string
{
    $minutes = random_int($minMinutes, $maxMinutes);
    return date('Y-m-d H:i:s', time() - ($minutes * 60));
}

function randomFuture(int $minMinutes, int $maxMinutes): string
{
    $minutes = random_int($minMinutes, $maxMinutes);
    return date('Y-m-d H:i:s', time() + ($minutes * 60));
}

// ─── Criar Clientes ─────────────────────────────────────────────────────────

echo "Criando clientes...\n";

$clients = [
    ['name' => 'Empresa ABC Ltda',      'telegram' => null],
    ['name' => 'ISP ConectaNet',        'telegram' => -1001234567890],
    ['name' => 'Escola Municipal Central', 'telegram' => null],
    ['name' => 'Hospital São Lucas',    'telegram' => -1009876543210],
    ['name' => 'Condomínio Parque Azul', 'telegram' => null],
];

$clientIds = [];

foreach ($clients as $client) {
    $id = uuid();
    $clientIds[] = $id;
    $stmt = $db->prepare('INSERT INTO clients (id, name, telegram_group_id) VALUES (:id, :name, :tg)');
    $stmt->execute([':id' => $id, ':name' => $client['name'], ':tg' => $client['telegram']]);
    echo "  → {$client['name']}\n";
}

echo "✓ " . count($clients) . " clientes criados\n";

// ─── Criar Equipamentos Mikrotik ────────────────────────────────────────────

echo "Criando equipamentos Mikrotik...\n";

$mikrotikDevices = [
    // Empresa ABC
    ['client_idx' => 0, 'name' => 'RB3011 - Sede',       'host' => '10.0.1.1',    'port' => 80,  'ssl' => false, 'user' => 'admin', 'pass' => 'admin123',  'status' => 'online',  'cpu' => 12, 'mem_free' => 134217728, 'mem_total' => 268435456, 'temp' => 41.0, 'volt' => 24.1, 'board' => 'RB3011UiAS',  'ros' => '7.14.3'],
    ['client_idx' => 0, 'name' => 'hAP ac² - Recepção',   'host' => '10.0.1.2',    'port' => 80,  'ssl' => false, 'user' => 'admin', 'pass' => 'admin123',  'status' => 'online',  'cpu' => 5,  'mem_free' => 83886080,  'mem_total' => 134217728, 'temp' => 35.5, 'volt' => 12.0, 'board' => 'hEX ac',      'ros' => '7.13.4'],

    // ISP ConectaNet
    ['client_idx' => 1, 'name' => 'CCR1036 - PoP Principal', 'host' => '45.4.112.13', 'port' => 80, 'ssl' => false, 'user' => 'teste',  'pass' => 'felipe29',  'status' => 'online',  'cpu' => 3,  'mem_free' => 190279680, 'mem_total' => 268435456, 'temp' => 42.0, 'volt' => 12.3, 'board' => 'CCR1036-12G-4S', 'ros' => '7.12.1'],
    ['client_idx' => 1, 'name' => 'RB750Gr3 - Cliente XYZ', 'host' => '45.4.112.18', 'port' => 80, 'ssl' => false, 'user' => 'admin',  'pass' => 'mikrotik',  'status' => 'online',  'cpu' => 18, 'mem_free' => 41943040,  'mem_total' => 67108864,  'temp' => 38.0, 'volt' => null, 'board' => 'RB750Gr3',    'ros' => '7.11.2'],
    ['client_idx' => 1, 'name' => 'hEX s - Filial Norte',   'host' => '189.55.12.34','port' => 443,'ssl' => true,  'user' => 'admin',  'pass' => 'admin123',  'status' => 'offline', 'cpu' => 0,  'mem_free' => 0,         'mem_total' => 67108864,  'temp' => null, 'volt' => null, 'board' => 'hEX s',       'ros' => '7.9.3'],

    // Escola
    ['client_idx' => 2, 'name' => 'RB4011 - Escola',       'host' => '172.16.0.1',  'port' => 80,  'ssl' => false, 'user' => 'admin', 'pass' => 'escola2024', 'status' => 'online',  'cpu' => 8,  'mem_free' => 201326592, 'mem_total' => 536870912, 'temp' => 33.0, 'volt' => 48.2, 'board' => 'RB4011iGS+',  'ros' => '7.14.3'],

    // Hospital
    ['client_idx' => 3, 'name' => 'CCR1009 - Hospital',     'host' => '10.10.0.1',   'port' => 443, 'ssl' => true,  'user' => 'admin', 'pass' => 'hospital!',  'status' => 'online',  'cpu' => 22, 'mem_free' => 134217728, 'mem_total' => 536870912, 'temp' => 48.5, 'volt' => 12.1, 'board' => 'CCR1009-8G-1S', 'ros' => '7.14.1'],
    ['client_idx' => 3, 'name' => 'cAP ac - Enfermaria',    'host' => '10.10.0.10',  'port' => 80,  'ssl' => false, 'user' => 'admin', 'pass' => 'hospital!',  'status' => 'unknown', 'cpu' => 0,  'mem_free' => 0,         'mem_total' => 33554432,  'temp' => null, 'volt' => null, 'board' => 'cAP ac',      'ros' => '7.8.1'],

    // Condomínio
    ['client_idx' => 4, 'name' => 'RB760iGS - Portaria',   'host' => '192.168.10.1','port' => 80,  'ssl' => false, 'user' => 'admin', 'pass' => 'cond2024',   'status' => 'online',  'cpu' => 2,  'mem_free' => 41943040,  'mem_total' => 67108864,  'temp' => 29.0, 'volt' => null, 'board' => 'hEX S',       'ros' => '7.13.4'],
];

$mikrotikIds = [];

foreach ($mikrotikDevices as $dev) {
    $id = uuid();
    $mikrotikIds[] = $id;

    // Criptografar senha (formato hex para BYTEA do PostgreSQL)
    $encrypted = $crypto->encrypt($dev['pass']);
    $encryptedBytes = base64_decode($encrypted, true);
    $hexPassword = '\x' . bin2hex($encryptedBytes);

    $stmt = $db->prepare('
        INSERT INTO mikrotiks (
            id, client_id, name, host, port, use_ssl, username, password_encrypted,
            device_type, current_status, status_since, last_checked_at,
            last_cpu_load, last_memory_free, last_memory_total, last_temperature, last_voltage,
            board_name, routeros_version
        ) VALUES (
            :id, :client_id, :name, :host, :port, :use_ssl, :username, :password_encrypted,
            :device_type, :status, :status_since, :last_checked,
            :cpu, :mem_free, :mem_total, :temp, :volt,
            :board, :ros
        )
    ');

    $now = date('Y-m-d H:i:s');
    $stmt->execute([
        ':id'                 => $id,
        ':client_id'          => $clientIds[$dev['client_idx']],
        ':name'               => $dev['name'],
        ':host'               => $dev['host'],
        ':port'               => $dev['port'],
        ':use_ssl'            => $dev['ssl'] ? 'true' : 'false',
        ':username'           => $dev['user'],
        ':password_encrypted' => $hexPassword,
        ':device_type'        => 'mikrotik',
        ':status'             => $dev['status'],
        ':status_since'       => $dev['status'] === 'offline' ? randomPast(30, 300) : randomPast(100, 5000),
        ':last_checked'       => randomPast(0, 2),
        ':cpu'                => $dev['cpu'],
        ':mem_free'           => $dev['mem_free'],
        ':mem_total'          => $dev['mem_total'],
        ':temp'               => $dev['temp'],
        ':volt'               => $dev['volt'],
        ':board'              => $dev['board'],
        ':ros'                => $dev['ros'],
    ]);

    echo "  → {$dev['name']} ({$dev['host']}) [{$dev['status']}]\n";
}

echo "✓ " . count($mikrotikDevices) . " equipamentos Mikrotik criados\n";

// ─── Criar Dispositivos Ping ────────────────────────────────────────────────

echo "Criando dispositivos ping...\n";

$pingDevices = [
    ['client_idx' => 0, 'name' => 'Camera - Estacionamento', 'host' => '10.0.1.100',  'status' => 'online',  'rtt' => 3],
    ['client_idx' => 0, 'name' => 'Camera - Entrada',        'host' => '10.0.1.101',  'status' => 'online',  'rtt' => 5],
    ['client_idx' => 1, 'name' => 'Servidor - CLIENTE XYZ',  'host' => '45.4.112.50', 'status' => 'online',  'rtt' => 12],
    ['client_idx' => 1, 'name' => 'AP - Ponto Hotel',        'host' => '189.55.12.99','status' => 'offline', 'rtt' => null],
    ['client_idx' => 2, 'name' => 'NAS - Backup Escola',     'host' => '172.16.0.50', 'status' => 'online',  'rtt' => 2],
    ['client_idx' => 3, 'name' => 'Sistema - Prontuário',    'host' => '10.10.0.200', 'status' => 'online',  'rtt' => 1],
    ['client_idx' => 3, 'name' => 'Impressora - Recepção',   'host' => '10.10.0.201', 'status' => 'offline', 'rtt' => null],
    ['client_idx' => 4, 'name' => 'Interfone - Bloco A',     'host' => '192.168.10.50','status' => 'online', 'rtt' => 4],
    ['client_idx' => 4, 'name' => 'Interfone - Bloco B',     'host' => '192.168.10.51','status' => 'online', 'rtt' => 6],
    ['client_idx' => 4, 'name' => 'Gerador - Área Comum',    'host' => '192.168.10.200','status' => 'unknown','rtt' => null],
];

foreach ($pingDevices as $dev) {
    $id = uuid();
    $mikrotikIds[] = $id;

    $stmt = $db->prepare('
        INSERT INTO mikrotiks (
            id, client_id, name, host, device_type,
            last_rtt_ms, current_status, status_since, last_checked_at,
            active
        ) VALUES (
            :id, :client_id, :name, :host, \'ping\',
            :rtt, :status, :status_since, :last_checked,
            true
        )
    ');

    $stmt->execute([
        ':id'            => $id,
        ':client_id'     => $clientIds[$dev['client_idx']],
        ':name'          => $dev['name'],
        ':host'          => $dev['host'],
        ':rtt'           => $dev['rtt'],
        ':status'        => $dev['status'],
        ':status_since'  => $dev['status'] === 'offline' ? randomPast(10, 120) : ($dev['status'] === 'unknown' ? null : randomPast(60, 5000)),
        ':last_checked'  => $dev['status'] === 'unknown' ? null : randomPast(0, 4),
    ]);

    $rttStr = $dev['rtt'] !== null ? "{$dev['rtt']}ms" : 'N/A';
    echo "  → {$dev['name']} ({$dev['host']}) [{$dev['status']}] RTT: {$rttStr}\n";
}

echo "✓ " . count($pingDevices) . " dispositivos ping criados\n";

// ─── Criar Hosts Netwatch ──────────────────────────────────────────────────

echo "Criando hosts netwatch...\n";

// Mapear IDs: primeiros 8 são Mikrotik, restante são ping
$mikrotikOnlyIds = array_slice($mikrotikIds, 0, count($mikrotikDevices));

$netwatchHosts = [
    // PoP Principal (index 2)
    [
        'mikrotik_idx' => 2, 'address' => '8.8.4.4',       'comment' => 'DNS Google Primário',    'status' => 'up',   'rtt' => 15,
    ],
    [
        'mikrotik_idx' => 2, 'address' => '8.8.8.8',       'comment' => 'DNS Google Secundário',  'status' => 'up',   'rtt' => 14,
    ],
    [
        'mikrotik_idx' => 2, 'address' => '1.1.1.1',       'comment' => 'Cloudflare DNS',         'status' => 'up',   'rtt' => 8,
    ],
    [
        'mikrotik_idx' => 2, 'address' => '45.4.112.18',   'comment' => 'RB750Gr3 - Cliente XYZ', 'status' => 'up',   'rtt' => 3,
    ],
    [
        'mikrotik_idx' => 2, 'address' => '189.55.12.34',  'comment' => 'hEX s - Filial Norte',   'status' => 'down', 'rtt' => null,
    ],
    [
        'mikrotik_idx' => 2, 'address' => '200.147.35.1',  'comment' => 'Gateway Backbone',        'status' => 'up',   'rtt' => 22,
    ],
    [
        'mikrotik_idx' => 2, 'address' => '10.0.0.1',      'comment' => 'Switch Core - TOR',      'status' => 'up',   'rtt' => 1,
    ],
    [
        'mikrotik_idx' => 2, 'address' => '10.0.0.2',      'comment' => 'Switch Core - BOT',      'status' => 'up',   'rtt' => 1,
    ],

    // RB3011 Sede (index 0)
    [
        'mikrotik_idx' => 0, 'address' => '10.0.1.100',    'comment' => 'Camera Estacionamento',  'status' => 'up',   'rtt' => 2,
    ],
    [
        'mikrotik_idx' => 0, 'address' => '10.0.1.101',    'comment' => 'Camera Entrada',         'status' => 'up',   'rtt' => 3,
    ],
    [
        'mikrotik_idx' => 0, 'address' => '10.0.1.200',    'comment' => 'Servidor de Arquivos',   'status' => 'up',   'rtt' => 1,
    ],
    [
        'mikrotik_idx' => 0, 'address' => '10.0.1.201',    'comment' => 'IPPD - Impressora',      'status' => 'down', 'rtt' => null,
    ],

    // RB4011 Escola (index 4)
    [
        'mikrotik_idx' => 4, 'address' => '172.16.0.10',   'comment' => 'PC - Sala Professores',  'status' => 'up',   'rtt' => 1,
    ],
    [
        'mikrotik_idx' => 4, 'address' => '172.16.0.11',   'comment' => 'PC - Coordenação',       'status' => 'up',   'rtt' => 1,
    ],
    [
        'mikrotik_idx' => 4, 'address' => '172.16.0.20',   'comment' => 'Notebook - Direção',     'status' => 'down', 'rtt' => null,
    ],
    [
        'mikrotik_idx' => 4, 'address' => '172.16.0.50',   'comment' => 'NAS Backup',             'status' => 'up',   'rtt' => 2,
    ],
    [
        'mikrotik_idx' => 4, 'address' => '172.16.0.100',  'comment' => 'AP - Salas Aulas',       'status' => 'up',   'rtt' => 1,
    ],
    [
        'mikrotik_idx' => 4, 'address' => '172.16.0.101',  'comment' => 'AP - Pátio',             'status' => 'down', 'rtt' => null,
    ],

    // CCR1009 Hospital (index 5)
    [
        'mikrotik_idx' => 5, 'address' => '10.10.0.100',   'comment' => 'Prontuário Eletrônico',  'status' => 'up',   'rtt' => 1,
    ],
    [
        'mikrotik_idx' => 5, 'address' => '10.10.0.101',   'comment' => 'Sistema PACS',           'status' => 'up',   'rtt' => 2,
    ],
    [
        'mikrotik_idx' => 5, 'address' => '10.10.0.150',   'comment' => 'Equipamento Raio-X',     'status' => 'down', 'rtt' => null,
    ],
    [
        'mikrotik_idx' => 5, 'address' => '10.10.0.200',   'comment' => 'Gateway Internet',       'status' => 'up',   'rtt' => 5,
    ],
    [
        'mikrotik_idx' => 5, 'address' => '10.10.0.201',   'comment' => 'Impressora Recepção',    'status' => 'down', 'rtt' => null,
    ],

    // RB760iGS Condomínio (index 7)
    [
        'mikrotik_idx' => 7, 'address' => '192.168.10.50',  'comment' => 'Interfone Bloco A',     'status' => 'up',   'rtt' => 3,
    ],
    [
        'mikrotik_idx' => 7, 'address' => '192.168.10.51',  'comment' => 'Interfone Bloco B',     'status' => 'up',   'rtt' => 4,
    ],
    [
        'mikrotik_idx' => 7, 'address' => '192.168.10.52',  'comment' => 'Interfone Bloco C',     'status' => 'down', 'rtt' => null,
    ],
    [
        'mikrotik_idx' => 7, 'address' => '192.168.10.200', 'comment' => 'Gerador de Energia',    'status' => 'up',   'rtt' => 1,
    ],
];

$netwatchIds = [];

foreach ($netwatchHosts as $host) {
    $id = uuid();
    $netwatchIds[] = $id;

    $statusSince = $host['status'] === 'down'
        ? randomPast(5, 90)
        : ($host['status'] === 'unknown' ? null : randomPast(60, 5000));

    $stmt = $db->prepare('
        INSERT INTO netwatch_hosts (
            id, mikrotik_id, host_address, comment, mikrotik_ref_id,
            current_status, status_since, last_checked_at, last_rtt_ms, active
        ) VALUES (
            :id, :mikrotik_id, :address, :comment, :ref_id,
            :status, :status_since, :last_checked, :rtt, true
        )
    ');

    $stmt->execute([
        ':id'            => $id,
        ':mikrotik_id'   => $mikrotikOnlyIds[$host['mikrotik_idx']],
        ':address'       => $host['address'],
        ':comment'       => $host['comment'],
        ':ref_id'        => '*' . strtoupper(bin2hex(random_bytes(2))),
        ':status'        => $host['status'],
        ':status_since'  => $statusSince,
        ':last_checked'  => randomPast(0, 3),
        ':rtt'           => $host['rtt'],
    ]);

    $rttStr = $host['rtt'] !== null ? "{$host['rtt']}ms" : '—';
    echo "  → {$host['comment']} ({$host['address']}) [{$host['status']}] RTT: {$rttStr}\n";
}

echo "✓ " . count($netwatchHosts) . " hosts netwatch criados\n";

// ─── Criar Health Log (histórico simulado) ──────────────────────────────────

echo "Criando histórico de saúde...\n";

$healthCount = 0;

foreach ($mikrotikDevices as $idx => $dev) {
    if ($dev['status'] === 'unknown') continue;

    $mikrotikId = $mikrotikIds[$idx];

    // Gerar 50 registros nos últimos 12 horas (um a cada ~15 min)
    for ($i = 50; $i >= 0; $i--) {
        $minutesAgo = $i * 15;
        $ts = date('Y-m-d H:i:s', time() - ($minutesAgo * 60));

        // Simular variação realista
        $cpu = max(0, min(100, $dev['cpu'] + random_int(-5, 5)));
        $memFree = $dev['mem_free'] > 0 ? max(0, $dev['mem_free'] + random_int(-5242880, 5242880)) : null;
        $memTotal = $dev['mem_total'];
        $temp = $dev['temp'] !== null ? round($dev['temp'] + random_int(-2, 2) + ($i > 40 ? 5 : 0), 1) : null;
        $volt = $dev['volt'] !== null ? round($dev['volt'] + (random_int(-10, 10) / 10), 1) : null;
        $uptime = random_int(800000, 1200000);

        $stmt = $db->prepare('
            INSERT INTO health_log (mikrotik_id, cpu_load, memory_free, memory_total, temperature, voltage, uptime, collected_at)
            VALUES (:id, :cpu, :mem_free, :mem_total, :temp, :volt, :uptime, :ts)
        ');
        $stmt->execute([
            ':id'        => $mikrotikId,
            ':cpu'       => $cpu,
            ':mem_free'  => $memFree,
            ':mem_total' => $memTotal,
            ':temp'      => $temp,
            ':volt'      => $volt,
            ':uptime'    => $uptime,
            ':ts'        => $ts,
        ]);
        $healthCount++;
    }
}

echo "✓ {$healthCount} registros de saúde gerados\n";

// ─── Criar Eventos de Status ───────────────────────────────────────────────

echo "Criando eventos de transição de status...\n";

$eventCount = 0;

// Evento: Filial Norte ficou offline há 2 horas
$stmt = $db->prepare('
    INSERT INTO mikrotik_events (mikrotik_id, status, started_at, ended_at, duration_seconds)
    VALUES (:id, :status, :started, NULL, NULL)
');
$stmt->execute([
    ':id'      => $mikrotikIds[4], // hEX s Filial Norte
    ':status'  => 'offline',
    ':started' => date('Y-m-d H:i:s', time() - 7200),
]);
$eventCount++;

// Eventos: Hospital ficou offline por 15 min e voltou
$stmt = $db->prepare('
    INSERT INTO mikrotik_events (mikrotik_id, status, started_at, ended_at, duration_seconds)
    VALUES (:id, :status, :started, :ended, :duration)
');
$stmt->execute([
    ':id'       => $mikrotikIds[5], // CCR1009 Hospital
    ':status'   => 'offline',
    ':started'  => date('Y-m-d H:i:s', time() - 3600),
    ':ended'    => date('Y-m-d H:i:s', time() - 2700),
    ':duration' => 900,
]);
$eventCount++;

$stmt->execute([
    ':id'       => $mikrotikIds[5],
    ':status'   => 'online',
    ':started'  => date('Y-m-d H:i:s', time() - 2700),
    ':ended'    => date('Y-m-d H:i:s', time() - 1200),
    ':duration' => 1500,
]);
$eventCount++;

// Eventos netwatch: Camera Entrada ficou down por 5 min
$stmt = $db->prepare('
    INSERT INTO netwatch_events (netwatch_host_id, status, started_at, ended_at, duration_seconds)
    VALUES (:id, :status, :started, :ended, :duration)
');
$stmt->execute([
    ':id'       => $netwatchIds[9], // Camera Entrada
    ':status'   => 'down',
    ':started'  => date('Y-m-d H:i:s', time() - 1800),
    ':ended'    => date('Y-m-d H:i:s', time() - 1500),
    ':duration' => 300,
]);
$eventCount++;

// Evento netwatch: NAS Escola está down há 30 min (em aberto)
$stmt->execute([
    ':id'       => $netwatchIds[15], // NAS Backup
    ':status'   => 'down',
    ':started'  => date('Y-m-d H:i:s', time() - 1800),
    ':ended'    => null,
    ':duration' => null,
]);
$eventCount++;

echo "✓ {$eventCount} eventos criados\n";

// ─── Resumo ─────────────────────────────────────────────────────────────────

echo "\n";
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║  Seed concluído com sucesso!                            ║\n";
echo "╠══════════════════════════════════════════════════════════╣\n";
echo "║                                                         ║\n";
echo sprintf("║  %-20s %3d equipamento(s) Mikrotik              ║\n", "Mikrotiks:", count($mikrotikDevices));
echo sprintf("║  %-20s %3d dispositivo(s) Ping                  ║\n", "Ping:", count($pingDevices));
echo sprintf("║  %-20s %3d host(s) Netwatch                     ║\n", "Hosts:", count($netwatchHosts));
echo sprintf("║  %-20s %3d registro(s) de saúde                 ║\n", "Health Log:", $healthCount);
echo sprintf("║  %-20s %3d evento(s) de transição               ║\n", "Eventos:", $eventCount);
echo "║                                                         ║\n";
echo "║  Dados dos equipamentos:                                ║\n";
echo "║  • Mikrotiks com IP fake (10.x, 172.x, 45.x)           ║\n";
echo "║  • Senhas fictícias (criptografadas no banco)           ║\n";
echo "║  • Métricas com variação realista                       ║\n";
echo "║  • Mixture de online/offline/unknown                    ║\n";
echo "║  • Hosts netwatch com status variados                   ║\n";
echo "║                                                         ║\n";
echo "║  ⚠️  Para limpar: DELETE FROM clients CASCADE            ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";
