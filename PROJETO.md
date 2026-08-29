# Mikrotik Watch - Documentação Técnica

## Visão Geral

O **Mikrotik Watch** é um painel de monitoramento web para gestão de múltiplos equipamentos de rede. O sistema suporta dois tipos de dispositivos:

- **Mikrotik (device_type = 'mikrotik')**: Coleta métricas detalhadas via API REST (CPU, memória, temperatura, hosts Netwatch)
- **Ping (device_type = 'ping')**: Monitoramento básico via ICMP ping, sem acesso à API

## Stack

| Camada | Tecnologia |
|--------|------------|
| Backend | PHP 8.4 puro (sem framework) |
| Frontend | HTML, CSS, JavaScript puro |
| Banco de Dados | PostgreSQL 17 |
| Gráficos | Chart.js via CDN |
| Autoload | PSR-4 (Composer) |
| Testes | PHPUnit 11 |
| Criptografia | libsodium (sodium_crypto_secretbox) |

## Arquitetura

### Padrão MVC Simples

```
HTTP Request → index.php (Front Controller) → Router → Controller → Service → Database
                                                                  ↓
                                                         View (PHP template)
```

### Camadas

1. **Front Controller** (`src/index.php`): Ponto de entrada único. Recebe todas as requisições HTTP, verifica autenticação via AuthMiddleware e despacha para o Router.

2. **Router** (`src/src/Router.php`): Mapeia URIs para controllers/ações. Suporta parâmetros dinâmicos (`{id}`).

3. **Controllers** (`src/src/Controller/`): Lógica de controle. Um controller por domínio:
   - `DashboardController` — Painel principal com status offline
   - `AuthController` — Login/logout via sessão PHP
   - `ClientController` — CRUD de clientes
   - `ClientHostsController` — Hosts Netwatch por cliente
   - `MikrotikController` — CRUD de equipamentos Mikrotik
   - `SettingsController` — Configurações do sistema

4. **Services** (`src/src/Service/`): Lógica de negócio e integração externa:
   - `MikrotikClient` — Cliente API REST do RouterOS (porta 80/443)
   - `CredentialCrypto` — Criptografia reversível de senhas (libsodium)
   - `Crypto` — Hash bcrypt para senhas de usuário
   - `Http/` — Transporte HTTP abstraído (CurlTransport, MockTransport)

5. **Middleware** (`src/src/Middleware/`): `AuthMiddleware` — Verifica sessão e timeout configurável.

6. **Views** (`src/views/`): Templates PHP para HTML. Layouts em `views/layouts/` (sidebar, header, footer).

7. **Config** (`src/config/`): Configuração centralizada. Loader do `.env`, conexão PDO, e definição de rotas.

8. **Cron** (`src/cron/`): Scripts de coleta periódica com mecanismo de lock.

### Fluxo de Autenticação

1. Usuário acessa `/login`
2. POST com email/senha (protegido por CSRF token)
3. Password verificado via `password_verify()` (bcrypt, cost 12)
4. Sessão PHP criada com `user_id`, `user_name`, `user_role`, `login_time`, `last_activity`
5. AuthMiddleware verifica sessão em rotas protegidas (timeout configurável)
6. Rotas de escrita (create, edit, delete, store, update) requerem role `admin`. Viewer é redirecionado para `/dashboard?error=forbidden` com mensagem flash
7. Rotas públicas: apenas `/login` e assets (`/assets/*`)

**Roles**:
- `admin`: Acesso total (CRUD em clientes, Mikrotiks, coleta manual)
- `viewer`: Somente leitura (dashboard, listagens, detalhes)

### Fluxo de Coleta (Cron)

```
Cron dispara → Acquire Lock (cron_locks) → Para cada Mikrotik ativo:
  → MikrotikClient.netwatch() → Compara com banco → Sincroniza hosts
  → Registra eventos de transição (netwatch_events)
  → Atualiza status do Mikrotik (online/offline)
→ Release Lock
```

## Estrutura do Banco de Dados

### Tabelas

| Tabela | Descrição |
|--------|-----------|
| `users` | Usuários do painel (login, senha bcrypt) |
| `clients` | Clientes cadastrados (agrupam equipamentos) |
| `mikrotiks` | Equipamentos Mikrotik RouterOS |
| `health_log` | Série temporal de métricas (CPU, memória, temperatura) — retido por 7 dias |
| `health_log_hourly` | Agregação horária de health_log — retido por 90 dias |
| `health_log_daily` | Agregação diária de health_log — retenção indefinida |
| `netwatch_hosts` | Hosts monitorados via Netwatch |
| `netwatch_events` | Transições de estado dos hosts (up↔down) |
| `mikrotik_events` | Transições de estado dos equipamentos (online↔offline) |
| `cron_locks` | Controle de execução dos crons |

### Design de Eventos vs Amostras

O projeto distingue dois tipos de dados temporais:

- **Amostras** (`health_log`): Uma linha por ciclo de coleta, independente de mudança. Usado para gráficos de tendência (CPU, memória, temperatura ao longo do tempo).

- **Eventos** (`netwatch_events`, `mikrotik_events`): Uma linha APENAS quando o status muda (up→down ou down→up). Usado para calcular downtime, exibir timeline de incidentes e cálculo de uptime SLA.

**Decisão de projeto**: Logs de eventos são registrados apenas em transições de estado, não em amostras. Isso minimiza o volume de dados e torna as queries de incidentes mais eficientes.

### Índices Importantes

- `health_log(mikrotik_id, collected_at DESC)` — Queries de série temporal
- `netwatch_events(netwatch_host_id, started_at DESC)` — Timeline por host
- `mikrotik_events(mikrotik_id, started_at DESC)` — Timeline por equipamento
- `netwatch_hosts(mikrotik_id)` — Lookup por Mikrotik

## Mecanismo de Lock dos Crons

Os crons utilizam a tabela `cron_locks` para evitar sobreposição de execuções:

```sql
-- Aquisição de lock (com timeout de 15 minutos)
UPDATE cron_locks
SET locked_at = now(), released_at = NULL
WHERE job_name = 'netwatch_sync'
  AND (locked_at IS NULL OR released_at IS NOT NULL
       OR locked_at < now() - INTERVAL '15 minutes')
```

**Comportamento**:
- Se o lock está livre (`locked_at IS NULL` ou `released_at IS NOT NULL`): adquire
- Se o lock está travado há mais de 15 minutos: adquire (timeout de segurança)
- Se o lock está travado há menos de 15 minutos: não adquire, aborta ciclo

**Jobs conhecidos**: `health_collect`, `netwatch_sync`, `ping_check`, `health_aggregate`

## Endpoints

### Páginas (GET)

| Rota | Descrição |
|------|-----------|
| `GET /` | Redireciona para dashboard |
| `GET /login` | Formulário de login |
| `GET /logout` | Encerra sessão |
| `GET /dashboard` | Painel principal com status offline |
| `GET /clients` | Lista de clientes |
| `GET /clients/create` | Formulário de criação |
| `GET /clients/{id}/edit` | Formulário de edição |
| `GET /clients/{id}/hosts` | Hosts Netwatch do cliente |
| `GET /mikrotiks` | Lista de equipamentos (com filtro) |
| `GET /mikrotiks/create` | Formulário de criação |
| `GET /mikrotiks/{id}` | Detalhes do equipamento |
| `GET /mikrotiks/{id}/edit` | Formulário de edição |
| `GET /settings` | Configurações |

### Ações (POST)

| Rota | Descrição |
|------|-----------|
| `POST /login` | Processar login |
| `POST /clients` | Criar cliente |
| `POST /clients/{id}` | Atualizar cliente |
| `POST /clients/{id}/delete` | Excluir cliente |
| `POST /mikrotiks/store` | Criar equipamento |
| `POST /mikrotiks/{id}` | Atualizar equipamento |
| `POST /mikrotiks/{id}/delete` | Excluir equipamento |
| `POST /mikrotiks/{id}/test` | Testar conexão (JSON) |

### API (JSON)

| Rota | Descrição |
|------|-----------|
| `GET /api/stats` | Estatísticas gerais |
| `GET /api/mikrotiks` | Lista de equipamentos |

## Segurança

- **Senhas Mikrotik**: Criptografadas com `sodium_crypto_secretbox` (libsodium), armazenadas como BYTEA
- **Chave de criptografia**: Variável de ambiente `CREDENTIAL_ENCRYPTION_KEY` (32 bytes, base64)
- **Senhas de usuário**: bcrypt com cost 12
- **Arquivo `.env`**: Permissão 600, nunca versionado
- **Prepared statements**: PDO contra SQL injection
- **Autenticação**: Sessão PHP com timeout configurável
- **CSRF tokens**: Proteção em todos os formulários POST via `CsrfMiddleware`
- **Roles**: Perfis `admin` (acesso total) e `viewer` (somente leitura)
- **API REST**: HTTP Basic Auth, porta 80/443 (nunca 8728)

## Ambientes

| Variável | Descrição | Valores |
|----------|-----------|---------|
| `APP_ENV` | Ambiente atual | `production`, `development`, `testing` |
| `APP_DEBUG` | Modo debug | `true`, `false` |

## Retenção de Dados (health_log)

O `health_log` armazena uma amostra por minuto por equipamento. Para evitar que a tabela cresça indefinidamente e otimizar consultas de longo período, o projeto implementa uma estratégia de três níveis de granularidade:

| Nível | Tabela | Granularidade | Retenção | Uso |
|-------|--------|---------------|----------|-----|
| **Bruto** | `health_log` | 1 minuto | **7 dias** | Gráficos de períodos curtos (até 48h) |
| **Horário** | `health_log_hourly` | 1 hora (AVG/MIN/MAX) | **90 dias** | Gráficos de períodos intermediários (3–90 dias) |
| **Diário** | `health_log_daily` | 1 dia (AVG/MIN/MAX) | **Indefinido** | Gráficos de períodos longos (acima de 90 dias) |

### Cron de Agregação

```bash
# Agregação e limpeza (1x por dia às 03:00)
0 3 * * * cd /var/www/Mikrotik-Watch/src && php cron/aggregate_health.php >> /var/log/mikrotik-watch/cron.log 2>&1
```

O script `aggregate_health.php` executa em ordem:
1. **Agregação horária**: `health_log > 24h` → `health_log_hourly` (INSERT ... ON CONFLICT DO UPDATE)
2. **Agregação diária**: `health_log_hourly > 90d` → `health_log_daily`
3. **Limpeza de brutos**: `DELETE health_log > 7d`
4. **Limpeza de horários**: `DELETE health_log_hourly > 90d`
5. `health_log_daily` não tem expiração (retenção indefinida)

Cada passo é isolado com `try/catch` — uma falha não impede os demais.

### Consultas por Período

A rota `GET /mikrotiks/{id}/health-data` escolhe automaticamente a tabela de origem conforme o período solicitado:

- **Até 48h**: `health_log` (dado bruto)
- **48h a 90 dias**: `health_log_hourly`
- **Acima de 90 dias**: `health_log_daily`

## Cron Jobs

### Equipamentos Mikrotik (device_type = 'mikrotik')

```bash
# Coleta de métricas (a cada 1 minuto) — apenas Mikrotiks
* * * * * cd /var/www/Mikrotik\ Watch/src && php cron/collect.php >> /var/log/mikrotik-watch/cron.log 2>&1

# Sincronização Netwatch (a cada 1 minuto) — apenas Mikrotiks
* * * * * cd /var/www/Mikrotik\ Watch/src && php cron/collect_netwatch.php >> /var/log/mikrotik-watch/cron.log 2>&1
```

### Equipamentos Ping (device_type = 'ping')

```bash
# Verificação por ICMP (a cada 5 minutos) — apenas dispositivos ping
*/5 * * * * cd /var/www/Mikrotik\ Watch/src && php cron/collect_ping.php >> /var/log/mikrotik-watch/cron.log 2>&1
```

### Agregação e Retenção (todos os equipamentos)

```bash
# Agregação de health_log (1x por dia às 03:00)
0 3 * * * cd /var/www/Mikrotik-Watch/src && php cron/aggregate_health.php >> /var/log/mikrotik-watch/cron.log 2>&1
```

**Importante**: Os crons de 1 minuto processam APENAS equipamentos com `device_type = 'mikrotik'`. O cron de ping processa APENAS equipamentos com `device_type = 'ping'`. Nunca misturam.

**Paralelismo**: Os crons `collect.php` e `collect_netwatch.php` usam `curl_multi` (via `MikrotikClient::batchGet`) para disparar todas as requisições HTTP aos Mikrotiks em paralelo, com limite de concorrência (max 30 simultâneas). Isso garante que o ciclo completo caiba na janela de 1 minuto mesmo com múltiplos equipamentos lentos ou offline. O timeout individual por requisição continua sendo 5 segundos.

## Testes

### Configuração

```bash
# Criar banco de testes
sudo ./database/setup_test.sh

# Ou manualmente
sudo ./tests/setup_test_db.php
```

### Execução

```bash
composer test              # Todos os testes
composer test:unit         # Apenas unitários
composer test:integration  # Apenas integração
composer test:coverage     # Com cobertura
```

### Cobertura

| Suite | Testes | Tipo |
|-------|--------|------|
| RouterTest | 8 | Unitário |
| CredentialCryptoTest | 17 | Unitário |
| MikrotikClientTest | 24 | Unitário |
| AuthMiddlewareTest | 15 | Unitário |
| MikrotikCrudTest | 21 | Unitário |
| BatchRequestTest | 3 | Unitário |
| ClientCrudTest | 13 | Integração |
| MikrotikCrudIntegrationTest | 8 | Integração |
| NetwatchSyncTest | 10 | Integração |
| PingDeviceTest | 5 | Integração |
| AdminViewerTest | 9 | Integração |
| HealthAggregationTest | 6 | Integração |
| **Total** | **137** | |

## Dependências PHP

| Extensão | Uso |
|----------|-----|
| `pdo_pgsql` | Conexão PostgreSQL |
| `curl` | Comunicação com API Mikrotik |
| `mbstring` | Manipulação de strings UTF-8 |
| `sodium` | Criptografia de senhas (nativo PHP 8.4) |
| `json` | Serialização JSON para API |

## Próximos Passos

### Prioridade Alta

- [ ] **Alertas via Telegram**: Integração com API do Telegram para envio de alertas quando equipamentos ou hosts ficarem offline. Usar o campo `telegram_group_id` da tabela `clients`.
- [ ] **Rate limiting**: Implementar rate limiting em endpoints de login para prevenir brute force.

### Prioridade Média

- [ ] **Dashboard com gráficos**: Adicionar gráficos de tendência de CPU/memória/temperatura via Chart.js.
- [ ] **Histórico de incidentes**: Página dedicada com timeline de incidents por equipamento/host.
- [ ] **Exportação de relatórios**: Exportar dados de uptime/down em CSV/PDF.

### Prioridade Baixa

- [ ] **Autenticação via LDAP/AD**: Integração com diretórios corporativos.
- [ ] **API pública**: Endpoints REST autenticados para integração externa.
- [ ] **Multi-tenant**: Suporte a múltiplas organizações isoladas.
