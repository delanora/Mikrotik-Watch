-- =============================================================================
-- Mikrotik Watch - Schema Inicial
-- PostgreSQL 17
--
-- Ordem de criação respeita dependências de FK:
--   1. users
--   2. clients
--   3. mikrotiks       (FK -> clients)
--   4. health_log      (FK -> mikrotiks)
--   5. netwatch_hosts  (FK -> mikrotiks)
--   6. netwatch_events (FK -> netwatch_hosts)
--   7. mikrotik_events (FK -> mikrotiks)
--   8. cron_locks
-- =============================================================================

-- Extensões necessárias
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ─── 1. users ────────────────────────────────────────────────────────────────
-- Usuários do painel de monitoramento. Cada usuário pode fazer login
-- e acessar o painel com as permissões definidas futuramente.
CREATE TABLE users (
    id            UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    name          VARCHAR(150) NOT NULL,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(20)  NOT NULL DEFAULT 'admin' CHECK (role IN ('admin', 'viewer')),
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);

COMMENT ON TABLE  users IS 'Usuários do painel de monitoramento (login e autenticação).';
COMMENT ON COLUMN users.email IS 'E-mail do usuário, utilizado como identificador de login (único).';
COMMENT ON COLUMN users.password_hash IS 'Hash bcrypt da senha, gerado via password_hash() no PHP.';

-- ─── 2. clients ──────────────────────────────────────────────────────────────
-- Clientes cadastrados no sistema. Cada cliente agrupa um ou mais
-- equipamentos Mikrotik. O campo telegram_group_id permite integração
-- futura com alertas via Telegram.
CREATE TABLE clients (
    id                UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    name              VARCHAR(200) NOT NULL,
    telegram_group_id BIGINT,
    active            BOOLEAN      NOT NULL DEFAULT true,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT now()
);

COMMENT ON TABLE  clients IS 'Clientes cadastrados. Cada cliente pode possuir múltiplos equipamentos Mikrotik.';
COMMENT ON COLUMN clients.telegram_group_id IS 'ID do grupo Telegram para envio de alertas deste cliente (NULL = sem integração).';
COMMENT ON COLUMN clients.active IS 'Se false, o cliente e seus equipamentos são ignorados nas coletas e listagens.';

-- ─── 3. mikrotiks ────────────────────────────────────────────────────────────
-- Equipamentos Mikrotik RouterOS vinculados a um cliente.
-- Cada linha representa um roteador físico ou virtual que será monitorado.
--
-- current_status / status_since / last_checked_at:
--   Esses campos funcionam como "cache" da última verificação conhecida.
--   São atualizados a cada ciclo de coleta (cron) e permitem exibir o
--   status atual nas telas de listagem sem precisar fazer JOIN com
--   mikrotik_events. O status é uma das três strings: 'online', 'offline'
--   ou 'unknown' (quando o equipamento ainda não foi checado ou quando
--   o estado é indefinido).
--
-- password_encrypted (BYTEA):
--   Armazena o nonce + ciphertext gerado pelo libsodium (sodium_crypto_secretbox).
--   O PHP decripta em runtime com a chave APP_SECRET.
CREATE TABLE mikrotiks (
    id                  UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    client_id           UUID          NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    name                VARCHAR(150)  NOT NULL,
    host                VARCHAR(500)  NOT NULL,
    port                INTEGER,
    use_ssl             BOOLEAN       DEFAULT true,
    username            VARCHAR(100),
    password_encrypted  BYTEA,
    device_type         VARCHAR(20)   NOT NULL DEFAULT 'mikrotik'
        CHECK (device_type IN ('mikrotik', 'ping')),
    last_rtt_ms         INTEGER,
    active              BOOLEAN       NOT NULL DEFAULT true,

    -- Status atual (cache da última verificação)
    current_status      VARCHAR(10)   NOT NULL DEFAULT 'unknown'
        CHECK (current_status IN ('online', 'offline', 'unknown', 'warning')),
    status_since        TIMESTAMPTZ,
    last_checked_at     TIMESTAMPTZ,

    -- Debounce de status (confirmação por falhas consecutivas)
    consecutive_failures INTEGER      NOT NULL DEFAULT 0,
    first_failure_at    TIMESTAMPTZ,

    -- Últimas métricas de saúde (cache para telas de listagem)
    last_cpu_load       SMALLINT,
    last_memory_free    BIGINT,
    last_memory_total   BIGINT,
    last_temperature    NUMERIC(5,2),
    last_voltage        NUMERIC(5,2),
    board_name          VARCHAR(100),
    routeros_version    VARCHAR(50),

    created_at          TIMESTAMPTZ   NOT NULL DEFAULT now()
);

COMMENT ON TABLE  mikrotiks IS 'Equipamentos Mikrotik RouterOS monitorados. Vinculados a um cliente.';
COMMENT ON COLUMN mikrotiks.current_status IS 'Status atual do equipamento: online, offline ou unknown. Cache da última verificação, atualizado pelo cron de coleta.';
COMMENT ON COLUMN mikrotiks.status_since IS 'Timestamp de quando o equipamento entrou no status atual. Usado para calcular duração de quedas.';
COMMENT ON COLUMN mikrotiks.last_checked_at IS 'Timestamp da última tentativa de verificação de status (sucesso ou falha).';
COMMENT ON COLUMN mikrotiks.password_encrypted IS 'Senha da API Mikrotik criptografada com libsodium (nonce + ciphertext). Decriptada em runtime pelo PHP.';
COMMENT ON COLUMN mikrotiks.device_type IS 'Tipo do equipamento: mikrotik (API REST) ou ping (ICMP).';
COMMENT ON COLUMN mikrotiks.last_rtt_ms IS 'Ultimo tempo de resposta ICMP em ms (apenas para device_type = ping).';

-- CHECK: mikrotik requer port, username, password_encrypted; ping so requer host
ALTER TABLE mikrotiks ADD CONSTRAINT chk_mikrotik_fields
    CHECK (
        (device_type = 'mikrotik' AND port IS NOT NULL AND username IS NOT NULL AND password_encrypted IS NOT NULL)
        OR
        (device_type = 'ping')
    );
COMMENT ON COLUMN mikrotiks.last_cpu_load IS 'Último valor de CPU load percentual coletado (cache para listagem).';
COMMENT ON COLUMN mikrotiks.last_memory_free IS 'Último valor de memória livre em bytes coletado (cache para listagem).';
COMMENT ON COLUMN mikrotiks.last_memory_total IS 'Último valor de memória total em bytes coletado (cache para listagem).';
COMMENT ON COLUMN mikrotiks.last_temperature IS 'Última temperatura coletada em °C (cache para listagem).';
COMMENT ON COLUMN mikrotiks.last_voltage IS 'Último valor de voltagem coletado em V (cache para listagem).';

-- Índices para mikrotiks
CREATE INDEX idx_mikrotiks_client_id     ON mikrotiks (client_id);
CREATE INDEX idx_mikrotiks_current_status ON mikrotiks (current_status);
CREATE INDEX idx_mikrotiks_device_type    ON mikrotiks (device_type);

-- ─── 4. health_log ───────────────────────────────────────────────────────────
-- Histórico de saúde do equipamento em granularidade fina.
-- Esta tabela é uma SÉRIE TEMPORAL CONTÍNUA: a cada ciclo de coleta
-- (ex.: a cada 5 minutos), uma nova linha é inserida para cada
-- equipamento ativo. Não representa transições de estado, mas sim
-- amostras periódicas de métricas.
--
-- Diferença entre health_log e mikrotik_events:
--   - health_log: insere uma linha a cada coleta, mesmo que o valor
--     não mude. Serve para gerar gráficos de tendência (CPU, memória,
--     temperatura ao longo do tempo).
--   - mikrotik_events: insere uma linha APENAS quando o status muda
--     (online → offline ou vice-versa). Serve para calcular downtime
--     e exibir timeline de incidentes.
CREATE TABLE health_log (
    id              BIGSERIAL    PRIMARY KEY,
    mikrotik_id     UUID         NOT NULL REFERENCES mikrotiks(id) ON DELETE CASCADE,
    cpu_load        SMALLINT,
    memory_free     BIGINT,
    memory_total    BIGINT,
    temperature     NUMERIC(5,2),
    voltage         NUMERIC(5,2),
    uptime          BIGINT,
    collected_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);

COMMENT ON TABLE  health_log IS 'Série temporal contínua de métricas de saúde. Uma linha por ciclo de coleta por equipamento (não representa transições de estado).';
COMMENT ON COLUMN health_log.cpu_load IS 'Percentual de uso de CPU no momento da coleta.';
COMMENT ON COLUMN health_log.memory_free IS 'Memória livre em bytes no momento da coleta.';
COMMENT ON COLUMN health_log.memory_total IS 'Memória total em bytes no momento da coleta.';
COMMENT ON COLUMN health_log.temperature IS 'Temperatura do equipamento em °C.';
COMMENT ON COLUMN health_log.voltage IS 'Voltagem de alimentação em volts.';
COMMENT ON COLUMN health_log.uptime IS 'Tempo de atividade do equipamento em segundos.';
COMMENT ON COLUMN health_log.collected_at IS 'Timestamp da coleta. Usado como eixo X em gráficos de série temporal.';

-- Índice composto para queries de série temporal (mais comum: buscar por equipamento + período)
CREATE INDEX idx_health_log_mikrotik_collected
    ON health_log (mikrotik_id, collected_at DESC);

-- ─── 5. netwatch_hosts ───────────────────────────────────────────────────────
-- Hosts monitorados dentro de cada Mikrotik via Netwatch.
-- O Netwatch é um recurso do RouterOS que faz ping/HTTP periodicamente
-- em hosts configurados e reporta status up/down.
--
-- O campo mikrotik_ref_id armazena o .id interno retornado pela API
-- do RouterOS (ex.: "*A1B2C3"), permitindo sincronização bidirecional.
--
-- current_status / status_since / last_checked_at:
--   Mesmo conceito que em mikrotiks: cache da última verificação
--   conhecida, atualizado pelo cron. Permite exibir status na listagem
--   sem JOIN com netwatch_events.
--
-- active:
--   Define se o host deve ser monitorado. Quando um host some do
--   Mikrotik durante a sincronização, active é setado para false
--   (soft delete), preservando o histórico de eventos associados.
CREATE TABLE netwatch_hosts (
    id                UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    mikrotik_id       UUID         NOT NULL REFERENCES mikrotiks(id) ON DELETE CASCADE,
    host_address      VARCHAR(500) NOT NULL,
    comment           VARCHAR(500),
    mikrotik_ref_id   VARCHAR(50),
    current_status    VARCHAR(10)  NOT NULL DEFAULT 'unknown'
        CHECK (current_status IN ('up', 'down', 'unknown', 'warning')),
    status_since      TIMESTAMPTZ,
    last_checked_at   TIMESTAMPTZ,

    -- Debounce de status (confirmação por falhas consecutivas)
    consecutive_failures INTEGER NOT NULL DEFAULT 0,
    first_failure_at  TIMESTAMPTZ,
    last_rtt_ms       INTEGER,
    active            BOOLEAN      NOT NULL DEFAULT true,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT now()
);

COMMENT ON TABLE  netwatch_hosts IS 'Hosts monitorados via Netwatch dentro de cada Mikrotik. Sincronizados da API do RouterOS.';
COMMENT ON COLUMN netwatch_hosts.mikrotik_ref_id IS 'ID interno do RouterOS (ex.: *A1B2C3), retornado pela API. Usado para sincronização.';
COMMENT ON COLUMN netwatch_hosts.current_status IS 'Status atual do host: up, down ou unknown. Cache da última verificação.';
COMMENT ON COLUMN netwatch_hosts.status_since IS 'Timestamp de quando o host entrou no status atual.';
COMMENT ON COLUMN netwatch_hosts.last_rtt_ms IS 'Último tempo de resposta do ping em milissegundos.';
COMMENT ON COLUMN netwatch_hosts.active IS 'Se false, o host não existe mais no Mikrotik (soft delete durante sincronização).';

-- Índices para netwatch_hosts
CREATE INDEX idx_netwatch_hosts_mikrotik_id    ON netwatch_hosts (mikrotik_id);
CREATE INDEX idx_netwatch_hosts_current_status ON netwatch_hosts (current_status);

-- ─── 6. netwatch_events ──────────────────────────────────────────────────────
-- Log de EVENTOS de mudança de status dos hosts monitorados.
-- Esta tabela representa TRANSIÇÕES DE ESTADO, não amostras.
-- Uma linha é inserida APENAS quando o status de um host muda
-- (de up para down, ou de down para up).
--
-- Fluxo:
--   1. Coleta detecta status "down" → INSERT com started_at = now(), ended_at = NULL
--   2. Coleta posterior detecta status "up" → UPDATE do evento aberto:
--      ended_at = now(), duration_seconds = ended_at - started_at
--
-- Diferença entre netwatch_events e health_log:
--   - netwatch_events: uma linha por transição (up→down ou down→up).
--     Usado para calcular downtime, exibir timeline de incidentes.
--   - health_log: uma linha por ciclo de coleta, independente de mudança.
--     Usado para gráficos de tendência (CPU, memória, etc.).
CREATE TABLE netwatch_events (
    id                  BIGSERIAL    PRIMARY KEY,
    netwatch_host_id    UUID         NOT NULL REFERENCES netwatch_hosts(id) ON DELETE CASCADE,
    status              VARCHAR(10)  NOT NULL CHECK (status IN ('up', 'down')),
    started_at          TIMESTAMPTZ  NOT NULL,
    ended_at            TIMESTAMPTZ,
    duration_seconds    INTEGER
);

COMMENT ON TABLE  netwatch_events IS 'Log de transições de estado dos hosts Netwatch. Uma linha por mudança (up→down ou down→up), não por amostra.';
COMMENT ON COLUMN netwatch_events.status IS 'Status que o host adquiriu neste evento: up ou down.';
COMMENT ON COLUMN netwatch_events.started_at IS 'Timestamp de quando o host entrou neste status.';
COMMENT ON COLUMN netwatch_events.ended_at IS 'Timestamp de quando o host saiu deste status. NULL enquanto o evento está em aberto.';
COMMENT ON COLUMN netwatch_events.duration_seconds IS 'Duração do evento em segundos. Calculado ao fechar o evento (quando ended_at é preenchido).';

-- Índice composto para queries de timeline por host
CREATE INDEX idx_netwatch_events_host_started
    ON netwatch_events (netwatch_host_id, started_at DESC);

-- ─── 7. mikrotik_events ──────────────────────────────────────────────────────
-- Log de EVENTOS de mudança de status do equipamento Mikrotik.
-- Mesmo conceito que netwatch_events, mas para o status do equipamento
-- em si (online/offline), não dos hosts internos.
--
-- Fluxo:
--   1. Coleta detecta que o Mikrotik ficou "offline" → INSERT com started_at = now()
--   2. Coleta posterior detecta "online" → UPDATE: ended_at = now(),
--      duration_seconds = ended_at - started_at
--
-- Diferença entre mikrotik_events e health_log:
--   - mikrotik_events: representa QUEDAS e RECUPEARAÇÕES do equipamento.
--     Cada linha = um incidente. Usado para cálculo de uptime SLA.
--   - health_log: representa AMOSTRAS periódicas de métricas.
--     Cada linha = um ponto no gráfico de CPU/memória/temperatura.
CREATE TABLE mikrotik_events (
    id                BIGSERIAL    PRIMARY KEY,
    mikrotik_id       UUID         NOT NULL REFERENCES mikrotiks(id) ON DELETE CASCADE,
    status            VARCHAR(10)  NOT NULL CHECK (status IN ('online', 'offline')),
    started_at        TIMESTAMPTZ  NOT NULL,
    ended_at          TIMESTAMPTZ,
    duration_seconds  INTEGER
);

COMMENT ON TABLE  mikrotik_events IS 'Log de transições de status dos equipamentos Mikrotik. Uma linha por mudança (online→offline ou vice-versa).';
COMMENT ON COLUMN mikrotik_events.status IS 'Status que o equipamento adquiriu neste evento: online ou offline.';
COMMENT ON COLUMN mikrotik_events.started_at IS 'Timestamp de quando o equipamento entrou neste status.';
COMMENT ON COLUMN mikrotik_events.ended_at IS 'Timestamp de quando o equipamento saiu deste status. NULL enquanto o evento está em aberto.';
COMMENT ON COLUMN mikrotik_events.duration_seconds IS 'Duração do evento em segundos. Calculado ao fechar o evento.';

-- Índice composto para queries de timeline por equipamento
CREATE INDEX idx_mikrotik_events_mikrotik_started
    ON mikrotik_events (mikrotik_id, started_at DESC);

-- ─── 8. cron_locks ───────────────────────────────────────────────────────────
-- Controle de execução para evitar sobreposição de crons.
-- Cada job tem um registro único. O cron adquire o lock antes de
-- executar e libera ao final. Se locked_at é recente (ex.: < 10 min)
-- e released_at é NULL, o job anterior travou — o novo não executa.
CREATE TABLE cron_locks (
    id            UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    job_name      VARCHAR(100) NOT NULL UNIQUE,
    locked_at     TIMESTAMPTZ,
    released_at   TIMESTAMPTZ
);

COMMENT ON TABLE  cron_locks IS 'Controle de execução de crons. Previne sobreposição quando um ciclo anterior ainda está rodando.';
COMMENT ON COLUMN cron_locks.job_name IS 'Nome único do job (ex.: health_collect, netwatch_sync).';
COMMENT ON COLUMN cron_locks.locked_at IS 'Timestamp de quando o lock foi adquirido. NULL = disponível.';
COMMENT ON COLUMN cron_locks.released_at IS 'Timestamp de quando o lock foi liberado. NULL = job em execução.';

-- Inserir locks para os jobs conhecidos
INSERT INTO cron_locks (job_name) VALUES
    ('health_collect'),
    ('netwatch_sync'),
    ('ping_check');

-- ─── Seed: Usuário admin padrão ──────────────────────────────────────────────
-- Senha: admin (hash bcrypt gerado com cost 12)
-- IMPORTANTE: Altere a senha após o primeiro login em produção!
INSERT INTO users (name, email, password_hash)
VALUES (
    'Administrador',
    'admin@example.com',
    '$2y$12$4VTss8nZSWW36dBxYk2vOuZbMXxR.qx4bnlG5gJjNTzCJEL.vMbim'
);
