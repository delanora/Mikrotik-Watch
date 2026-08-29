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

// ─── Criar 30 Clientes ─────────────────────────────────────────────────────

echo "Criando 30 clientes...\n";

$clients = [
    ['name' => 'Empresa ABC Ltda',            'telegram' => null],
    ['name' => 'ISP ConectaNet',              'telegram' => -1001234567890],
    ['name' => 'Escola Municipal Central',     'telegram' => null],
    ['name' => 'Hospital São Lucas',          'telegram' => -1009876543210],
    ['name' => 'Condomínio Parque Azul',       'telegram' => null],
    ['name' => 'Loja Virtual ShopMax',         'telegram' => -1001112223330],
    ['name' => 'Restaurante Sabor & Arte',     'telegram' => null],
    ['name' => 'Posto de Saúde Vila Nova',     'telegram' => -1004445556660],
    ['name' => 'Indústria MetalPro',           'telegram' => null],
    ['name' => 'Hotel Pousada Estrela',        'telegram' => -1007778889990],
    ['name' => 'Farmácia Popular',             'telegram' => null],
    ['name' => 'Clínica OdontoVida',           'telegram' => -1001110002220],
    ['name' => 'Autopeças Brasil',             'telegram' => null],
    ['name' => 'Escola Técnica EngenhoTech',   'telegram' => -1003334445550],
    ['name' => 'Supermercado Família',         'telegram' => null],
    ['name' => 'Padaria Pão Dourado',          'telegram' => null],
    ['name' => 'Oficina Mecânica MotorMax',    'telegram' => -1006667778880],
    ['name' => 'Petshop Amigo Animal',         'telegram' => null],
    ['name' => 'Academia Corpo Forte',         'telegram' => -1009990001110],
    ['name' => 'Escritório Advocacia Lima',    'telegram' => null],
    ['name' => 'Construtora Alvenaria',        'telegram' => -1002223334440],
    ['name' => 'Transportadora Rápida',        'telegram' => null],
    ['name' => 'Escola de Idiomas Global',      'telegram' => -1005556667770],
    ['name' => 'Salão de Beleza Glamour',      'telegram' => null],
    ['name' => 'Estúdio Fotografia Capture',   'telegram' => -1008889990000],
    ['name' => 'Serralheria Ferroarte',         'telegram' => null],
    ['name' => 'Lavanderia Express',            'telegram' => -1001112224440],
    ['name' => 'Clinica Veterinária AnimalCare','telegram' => null],
    ['name' => 'Bar & Grill Noite Viva',       'telegram' => -1003335557770],
    ['name' => 'Imobiliária CasaBem',          'telegram' => null],
];

$clientIds = [];

foreach ($clients as $client) {
    $id = uuid();
    $clientIds[] = $id;
    $stmt = $db->prepare('INSERT INTO clients (id, name, telegram_group_id) VALUES (:id, :name, :tg)');
    $stmt->execute([':id' => $id, ':name' => $client['name'], ':tg' => $client['telegram']]);
}

echo "✓ " . count($clients) . " clientes criados\n";

// ─── Criar Equipamentos Mikrotik (18 = dobro dos 9 originais) ───────────────

echo "Criando equipamentos Mikrotik...\n";

// Placas e versões disponíveis para randomizar
$boards = ['RB3011UiAS', 'hEX ac', 'CCR1036-12G-4S', 'RB750Gr3', 'hEX s', 'RB4011iGS+', 'CCR1009-8G-1S', 'cAP ac', 'hEX S', 'CCR1016-12G-4S', 'RB2011UiAS', 'RB1100AHx4', 'hAP ac³', 'ChR 1009'];
$rosVersions = ['7.14.3', '7.13.4', '7.12.1', '7.11.2', '7.9.3', '7.14.1', '7.8.1', '7.16.2'];
$statuses = ['online', 'online', 'online', 'online', 'offline', 'unknown'];
$passChoices = ['admin123', 'felipe29', 'mikrotik', 'cond2024', 'escola2024', 'hospital!'];

$mikrotikDevices = [];
$hosts = [
    '10.0.1', '10.0.2', '172.16.0', '172.16.1', '192.168.1', '192.168.10',
    '45.4.112', '189.55.12', '10.10.0', '10.20.0', '172.20.0', '192.168.50',
];

$names = [
    'RB3011 - Sede', 'hAP ac² - Recepção', 'CCR1036 - PoP Principal', 'RB750Gr3 - Filial',
    'hEX s - Norte', 'RB4011 - Unidade 2', 'CCR1009 - Datacenter', 'cAP ac - Ponto WiFi',
    'RB760iGS - Portaria', 'CCR1016 - Backbone', 'RB2011 - Backup', 'RB1100AHx4 - Core',
    'hAP ac³ - Sala 3', 'ChR 1009 - Edge', 'RB3011 - Agência 2', 'hEX s - Agência 3',
    'RB4011 - TOR Switch', 'CCR1009 - PoP 2',
];

for ($i = 0; $i < 18; $i++) {
    $clientIdx = $i % count($clients);
    $subnet = $hosts[$i % count($hosts)];
    $lastOctet = ($i % 250) + 1;
    $port = ($i % 3 === 0) ? 443 : 80;
    $ssl = ($port === 443);
    $status = $statuses[array_rand($statuses)];
    $cpu = ($status === 'online') ? random_int(1, 35) : 0;
    $memTotal = [67108864, 134217728, 268435456, 536870912][$i % 4];
    $memFree = ($status === 'online') ? random_int(intval($memTotal * 0.2), intval($memTotal * 0.8)) : 0;
    $temp = ($status === 'online') ? round(random_int(28, 55) + 0.0, 1) : null;
    $volt = ($status === 'online') ? round(random_int(100, 485) / 10, 1) : null;

    $mikrotikDevices[] = [
        'client_idx' => $clientIdx,
        'name'       => $names[$i],
        'host'       => "{$subnet}.{$lastOctet}",
        'port'       => $port,
        'ssl'        => $ssl,
        'user'       => 'admin',
        'pass'       => $passChoices[$i % count($passChoices)],
        'status'     => $status,
        'cpu'        => $cpu,
        'mem_free'   => $memFree,
        'mem_total'  => $memTotal,
        'temp'       => $temp,
        'volt'       => $volt,
        'board'      => $boards[$i % count($boards)],
        'ros'        => $rosVersions[$i % count($rosVersions)],
    ];
}

$mikrotikIds = [];

foreach ($mikrotikDevices as $dev) {
    $id = uuid();
    $mikrotikIds[] = $id;

    $encrypted = $crypto->encrypt($dev['pass']);
    $encryptedBytes = base64_decode($encrypted, true);
    $hexPassword = '\\x' . bin2hex($encryptedBytes);

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
        ':status_since'       => $dev['status'] === 'offline' ? randomPast(5, 120) : randomPast(60, 5000),
        ':last_checked'       => randomPast(0, 3),
        ':cpu'                => $dev['cpu'],
        ':mem_free'           => $dev['mem_free'],
        ':mem_total'          => $dev['mem_total'],
        ':temp'               => $dev['temp'],
        ':volt'               => $dev['volt'],
        ':board'              => $dev['board'],
        ':ros'                => $dev['ros'],
    ]);
}

echo "✓ " . count($mikrotikDevices) . " equipamentos Mikrotik criados\n";

// ─── Criar Dispositivos Ping (20 = dobro dos 10 originais) ─────────────────

echo "Criando dispositivos ping...\n";

$pingNames = [
    'Camera - Estacionamento', 'Camera - Entrada', 'Camera - Fundos', 'Camera - Salão',
    'Servidor - Backup', 'NAS - Arquivos', 'AP - Ponto Central', 'AP - Ponto Norte',
    'AP - Ponto Sul', 'Impressora - Recepção', 'Impressora - Escritório', 'Interfone - Bloco A',
    'Interfone - Bloco B', 'Interfone - Bloco C', 'Gerador - Área Comum', 'UPS - Rack Principal',
    'Switch - Andar 1', 'Switch - Andar 2', 'Notebook - Direção', 'Tablet - Recepção',
];
$pingHosts = [
    '10.0.1.100', '10.0.1.101', '10.0.1.102', '10.0.1.103',
    '45.4.112.50', '45.4.112.51', '172.16.0.100', '172.16.0.101',
    '172.16.1.100', '10.10.0.200', '10.10.0.201', '192.168.10.50',
    '192.168.10.51', '192.168.10.52', '192.168.10.200', '192.168.10.201',
    '172.20.0.10', '172.20.0.11', '10.20.0.50', '10.20.0.51',
];

$pingDevices = [];
for ($i = 0; $i < 20; $i++) {
    $clientIdx = $i % count($clients);
    $status = ($i % 7 === 0) ? 'offline' : (($i % 11 === 0) ? 'unknown' : 'online');
    $rtt = ($status === 'online') ? random_int(1, 15) : null;

    $pingDevices[] = [
        'client_idx' => $clientIdx,
        'name'       => $pingNames[$i],
        'host'       => $pingHosts[$i],
        'status'     => $status,
        'rtt'        => $rtt,
    ];
}

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
}

echo "✓ " . count($pingDevices) . " dispositivos ping criados\n";

// ─── Criar Hosts Netwatch (54+ = dobro dos 27 originais) ───────────────────

echo "Criando hosts netwatch...\n";

$mikrotikOnlyIds = array_slice($mikrotikIds, 0, count($mikrotikDevices));

$netwatchHosts = [];
$netwatchComments = [
    'DNS Google Primário', 'DNS Google Secundário', 'Cloudflare DNS', 'Switch Core - TOR',
    'Switch Core - BOT', 'Gateway Backbone', 'Servidor Web', 'Servidor SMTP',
    'Switch Acces - Andar 1', 'Switch Acces - Andar 2', 'AP - Sala 101', 'AP - Sala 102',
    'Camera - Entrada', 'Camera - Estacionamento', 'Camera - Fundos', 'Impressora - Térreo',
    'Notebook - Direção', 'NAS Backup', 'Servidor Arquivos', 'IP Phone - Recepção',
    'IP Phone - Financeiro', 'Telefonia - PBX', 'Gateway VPN', 'Firewall Edge',
    'Servidor DNS Interno', 'Cache Proxy', 'Monitor - Rack 1', 'Monitor - Rack 2',
    'UPS - Rack Principal', 'UPS - Rack Secundário', 'KVM - Rack 1', 'KVM - Rack 2',
    'Switch Management', 'Router Backup', 'Antena - Telhado', 'Antena - Ponto Alto',
    'Repetidor - Fundos', 'Repetidor - Ala Norte', 'Impressora 3D', 'Scanner Documentos',
    'Interfone Principal', 'Portão Eletrônico', 'Câmera IR - Exterior', 'Alarme Central',
    ' Sensor - Portaria', 'Sensor - Estacionamento', 'Sensores - Greens', 'Sensores - Sala Servidores',
    'Iluminação LED', 'Ar Condicionado - Sala', 'Ar Condicionado - Rack', 'CFTV - Sala 2',
    'CFTV - Corredor', 'GPS Rastreamento',
];

for ($i = 0; $i < 54; $i++) {
    $mikrotikIdx = $i % count($mikrotikDevices);
    $baseSubnet = $hosts[$mikrotikIdx % count($hosts)];
    $hostIp = $baseSubnet . '.' . (($i % 250) + 50);
    $comment = $netwatchComments[$i % count($netwatchComments)];
    $status = ($i % 6 === 0) ? 'down' : (($i % 13 === 0) ? 'unknown' : 'up');
    $rtt = ($status === 'up') ? random_int(1, 25) : null;

    $netwatchHosts[] = [
        'mikrotik_idx' => $mikrotikIdx,
        'address'      => $hostIp,
        'comment'      => $comment,
        'status'       => $status,
        'rtt'          => $rtt,
    ];
}

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
}

echo "✓ " . count($netwatchHosts) . " hosts netwatch criados\n";

// ─── Criar Health Log (histórico simulado) ──────────────────────────────────

echo "Criando histórico de saúde...\n";

$healthCount = 0;

foreach ($mikrotikDevices as $idx => $dev) {
    if ($dev['status'] === 'unknown') continue;

    $mikrotikId = $mikrotikIds[$idx];

    for ($i = 50; $i >= 0; $i--) {
        $minutesAgo = $i * 15;
        $ts = date('Y-m-d H:i:s', time() - ($minutesAgo * 60));

        $cpu = max(0, min(100, $dev['cpu'] + random_int(-5, 5)));
        $memFree = $dev['mem_free'] > 0 ? max(0, $dev['mem_free'] + random_int(-5242880, 5242880)) : null;
        $memTotal = $dev['mem_total'];
        $temp = $dev['temp'] !== null ? round($dev['temp'] + random_int(-2, 2), 1) : null;
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

// 5 eventos de Mikrotik
$eventData = [
    [$mikrotikIds[4], 'offline', 7200, null, null],
    [$mikrotikIds[5], 'offline', 3600, 2700, 900],
    [$mikrotikIds[5], 'online',  2700, 1200, 1500],
    [$mikrotikIds[9], 'offline', 1800, null, null],
    [$mikrotikIds[12], 'offline', 600, 300, 300],
];

$stmt = $db->prepare('
    INSERT INTO mikrotik_events (mikrotik_id, status, started_at, ended_at, duration_seconds)
    VALUES (:id, :status, :started, :ended, :duration)
');

foreach ($eventData as [$mikrotikId, $status, $agoStarted, $agoEnded, $duration]) {
    $started = date('Y-m-d H:i:s', time() - $agoStarted);
    $ended = $agoEnded !== null ? date('Y-m-d H:i:s', time() - $agoEnded) : null;
    $stmt->execute([':id' => $mikrotikId, ':status' => $status, ':started' => $started, ':ended' => $ended, ':duration' => $duration]);
    $eventCount++;
}

// 5 eventos de netwatch
$nwEventData = [
    [$netwatchIds[12], 'down', 1800, 1500, 300],
    [$netwatchIds[15], 'down', 1800, null, null],
    [$netwatchIds[20], 'down', 900, 600, 300],
    [$netwatchIds[30], 'down', 3600, 3000, 600],
    [$netwatchIds[40], 'down', 600, null, null],
];

$stmt = $db->prepare('
    INSERT INTO netwatch_events (netwatch_host_id, status, started_at, ended_at, duration_seconds)
    VALUES (:id, :status, :started, :ended, :duration)
');

foreach ($nwEventData as [$hostId, $status, $agoStarted, $agoEnded, $duration]) {
    $started = date('Y-m-d H:i:s', time() - $agoStarted);
    $ended = $agoEnded !== null ? date('Y-m-d H:i:s', time() - $agoEnded) : null;
    $stmt->execute([':id' => $hostId, ':status' => $status, ':started' => $started, ':ended' => $ended, ':duration' => $duration]);
    $eventCount++;
}

echo "✓ {$eventCount} eventos criados\n";

// ─── Resumo ─────────────────────────────────────────────────────────────────

echo "\n";
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║  Seed concluído com sucesso!                            ║\n";
echo "╠══════════════════════════════════════════════════════════╣\n";
echo "║                                                         ║\n";
echo sprintf("║  %-20s %3d cliente(s)                              ║\n", "Clientes:", count($clients));
echo sprintf("║  %-20s %3d equipamento(s) Mikrotik              ║\n", "Mikrotiks:", count($mikrotikDevices));
echo sprintf("║  %-20s %3d dispositivo(s) Ping                  ║\n", "Ping:", count($pingDevices));
echo sprintf("║  %-20s %3d host(s) Netwatch                     ║\n", "Hosts:", count($netwatchHosts));
echo sprintf("║  %-20s %3d registro(s) de saúde                 ║\n", "Health Log:", $healthCount);
echo sprintf("║  %-20s %3d evento(s) de transição               ║\n", "Eventos:", $eventCount);
echo "║                                                         ║\n";
echo "║  ⚠️  Para limpar: DELETE FROM clients CASCADE            ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";
