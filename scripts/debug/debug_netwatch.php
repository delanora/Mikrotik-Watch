<?php
/**
 * Script de diagnóstico - Testa a API netwatch diretamente
 * Uso: php cron/debug_netwatch.php <mikrotik_id>
 * 
 * Mostra a resposta bruta da API e o que seria sincronizado.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';

use App\Service\MikrotikClient;
use App\Service\CredentialCrypto;

// Pegar ID do Mikrotik da linha de comando
$mikrotikId = $argv[1] ?? null;

if ($mikrotikId === null) {
    echo "Uso: php cron/debug_netwatch.php <mikrotik_id>\n";
    echo "Listando Mikrotiks disponíveis...\n\n";
    
    $db = new PDO(
        "pgsql:host={$config['database']['host']};port={$config['database']['port']};dbname={$config['database']['name']}",
        $config['database']['user'],
        $config['database']['password']
    );
    
    $stmt = $db->query("SELECT id, name, host, device_type FROM mikrotiks WHERE active = true ORDER BY name");
    $mikrotiks = $stmt->fetchAll();
    
    echo str_pad("ID", 38) . " | " . str_pad("Nome", 20) . " | " . str_pad("Host", 20) . " | Tipo\n";
    echo str_repeat("-", 100) . "\n";
    foreach ($mikrotiks as $m) {
        echo "{$m['id']} | {$m['name']} | {$m['host']} | {$m['device_type']}\n";
    }
    exit(0);
}

// Conectar ao banco
$db = new PDO(
    "pgsql:host={$config['database']['host']};port={$config['database']['port']};dbname={$config['database']['name']}",
    $config['database']['user'],
    $config['database']['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Buscar Mikrotik
$stmt = $db->prepare('SELECT * FROM mikrotiks WHERE id = :id AND active = true');
$stmt->execute([':id' => $mikrotikId]);
$mikrotik = $stmt->fetch();

if ($mikrotik === false) {
    echo "Mikrotik não encontrado: {$mikrotikId}\n";
    exit(1);
}

echo "=== Diagnóstico Netwatch ===\n";
echo "Mikrotik: {$mikrotik['name']} ({$mikrotik['host']})\n";
echo "Tipo: {$mikrotik['device_type']}\n\n";

if ($mikrotik['device_type'] !== 'mikrotik') {
    echo "Este Mikrotik não é do tipo 'mikrotik'. Netwatch não disponível.\n";
    exit(0);
}

// Descriptografar senha
$crypto = new CredentialCrypto($config['mikrotik']['credential_key']);
$pwRaw = $mikrotik['password_encrypted'];
if (is_resource($pwRaw)) {
    $pwRaw = stream_get_contents($pwRaw);
}
$encryptedBase64 = base64_encode($pwRaw);
$password = $crypto->decrypt($encryptedBase64);

// Criar cliente
$client = new MikrotikClient(
    host: $mikrotik['host'],
    username: $mikrotik['username'],
    password: $password,
    port: (int) $mikrotik['port'],
    useSsl: (bool) $mikrotik['use_ssl'],
    verifySsl: !$config['mikrotik']['allow_self_signed'],
    timeout: $config['mikrotik']['default_timeout'],
);

echo "--- Testando /rest/tool/netwatch ---\n\n";

try {
    $response = $client->get('/rest/tool/netwatch');
    
    echo "Tipo da resposta: " . gettype($response) . "\n";
    echo "Tamanho: " . count($response) . " itens\n\n";
    
    if (empty($response)) {
        echo "⚠️  Resposta vazia! Nenhum host configurado no netwatch deste Mikrotik.\n";
        exit(0);
    }
    
    // Mostrar chaves do primeiro item
    $firstItem = reset($response);
    echo "Chaves do primeiro item: " . implode(', ', array_keys($firstItem)) . "\n\n";
    
    // Mostrar todos os hosts
    echo "--- Hosts encontrados ---\n";
    echo str_pad("#", 4) . " | " . str_pad(".id", 10) . " | " . str_pad("host", 20) . " | " . str_pad("status", 10) . " | comment\n";
    echo str_repeat("-", 80) . "\n";
    
    $i = 1;
    foreach ($response as $item) {
        $refId = $item['.id'] ?? 'N/A';
        $host = $item['host'] ?? 'N/A';
        $status = $item['status'] ?? 'N/A';
        $comment = $item['comment'] ?? '';
        
        echo str_pad((string) $i, 4) . " | " 
             . str_pad($refId, 10) . " | " 
             . str_pad($host, 20) . " | " 
             . str_pad($status, 10) . " | " 
             . $comment . "\n";
        $i++;
    }
    
    echo "\n--- Verificação no banco de dados ---\n\n";
    
    // Buscar hosts existentes no banco
    $stmt = $db->prepare('SELECT id, host_address, mikrotik_ref_id, current_status, active FROM netwatch_hosts WHERE mikrotik_id = :mikrotik_id');
    $stmt->execute([':mikrotik_id' => $mikrotikId]);
    $existingHosts = $stmt->fetchAll();
    
    echo "Hosts no banco: " . count($existingHosts) . "\n";
    
    if (!empty($existingHosts)) {
        echo str_pad("DB ID", 38) . " | " . str_pad("host_address", 20) . " | " . str_pad("mikrotik_ref_id", 15) . " | " . str_pad("status", 10) . " | active\n";
        echo str_repeat("-", 100) . "\n";
        
        foreach ($existingHosts as $existing) {
            echo "{$existing['id']} | " 
                 . str_pad($existing['host_address'], 20) . " | " 
                 . str_pad($existing['mikrotik_ref_id'] ?? 'NULL', 15) . " | " 
                 . str_pad($existing['current_status'], 10) . " | " 
                 . ($existing['active'] ? 'true' : 'false') . "\n";
        }
    }
    
    // Verificar correspondência
    echo "\n--- Análise de correspondência ---\n\n";
    
    $apiByRef = [];
    foreach ($response as $item) {
        $refId = $item['.id'] ?? null;
        if ($refId !== null) {
            $apiByRef[(string) $refId] = $item;
        }
    }
    
    $existingByRef = [];
    foreach ($existingHosts as $existing) {
        if ($existing['mikrotik_ref_id'] !== null) {
            $existingByRef[(string) $existing['mikrotik_ref_id']] = $existing;
        }
    }
    
    $matched = 0;
    $newInApi = 0;
    $onlyInDb = 0;
    
    foreach ($apiByRef as $refId => $apiHost) {
        if (isset($existingByRef[$refId])) {
            $matched++;
        } else {
            $newInApi++;
            echo "NOVO na API (não existe no banco): {$apiHost['host']} (ref: {$refId})\n";
        }
    }
    
    foreach ($existingByRef as $refId => $existing) {
        if (!isset($apiByRef[$refId])) {
            $onlyInDb++;
            echo "SÓ NO BANCO (não existe na API): {$existing['host_address']} (ref: {$refId})\n";
        }
    }
    
    echo "\nResumo:\n";
    echo "  Correspondem: {$matched}\n";
    echo "  Novos na API: {$newInApi}\n";
    echo "  Só no banco: {$onlyInDb}\n";
    
} catch (\Throwable $e) {
    echo "ERRO: {$e->getMessage()}\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n=== Fim do diagnóstico ===\n";
