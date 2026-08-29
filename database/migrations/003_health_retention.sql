-- =============================================================================
-- Migration 003: Retenção e Agregação de health_log
--
-- Cria duas tabelas de agregação para otimizar consultas de longo período:
--   - health_log_hourly: dados agregados por hora (retidos por 90 dias)
--   - health_log_daily: dados agregados por dia (retidos indefinidamente)
--
-- A tabela health_log (dados brutos) é mantida por apenas 7 dias.
-- O script src/cron/aggregate_health.php executa a agregação e limpeza diariamente.
-- =============================================================================

-- ─── health_log_hourly ───────────────────────────────────────────────────────
-- Amostras horárias agregadas a partir de health_log.
-- Uma linha por mikrotik_id + hora, com estatísticas AVG/MIN/MAX.
-- Retidos por 90 dias (depois disso, agregados em health_log_daily).
CREATE TABLE IF NOT EXISTS health_log_hourly (
    id                BIGSERIAL    PRIMARY KEY,
    mikrotik_id       UUID         NOT NULL REFERENCES mikrotiks(id) ON DELETE CASCADE,
    hour_bucket       TIMESTAMPTZ  NOT NULL,
    avg_cpu_load      NUMERIC(5,2),
    min_cpu_load      SMALLINT,
    max_cpu_load      SMALLINT,
    avg_memory_free   NUMERIC(14,2),
    avg_memory_total  NUMERIC(14,2),
    avg_temperature   NUMERIC(6,2),
    min_temperature   NUMERIC(5,2),
    max_temperature   NUMERIC(5,2),
    avg_voltage       NUMERIC(6,3),
    sample_count      INTEGER      NOT NULL DEFAULT 0,

    UNIQUE (mikrotik_id, hour_bucket)
);

COMMENT ON TABLE  health_log_hourly IS 'Agregação horária de métricas de saúde. Uma linha por hora por equipamento. Retida por 90 dias.';
COMMENT ON COLUMN health_log_hourly.hour_bucket IS 'Timestamp truncado para o início da hora (ex.: 2026-08-29 14:00:00).';
COMMENT ON COLUMN health_log_hourly.avg_cpu_load IS 'Média do percentual de CPU nesta hora.';
COMMENT ON COLUMN health_log_hourly.min_cpu_load IS 'Menor valor de CPU nesta hora.';
COMMENT ON COLUMN health_log_hourly.max_cpu_load IS 'Maior valor de CPU nesta hora.';
COMMENT ON COLUMN health_log_hourly.avg_memory_free IS 'Média de memória livre (bytes) nesta hora.';
COMMENT ON COLUMN health_log_hourly.avg_memory_total IS 'Média de memória total (bytes) nesta hora.';
COMMENT ON COLUMN health_log_hourly.avg_temperature IS 'Média de temperatura (°C) nesta hora.';
COMMENT ON COLUMN health_log_hourly.min_temperature IS 'Menor temperatura (°C) nesta hora.';
COMMENT ON COLUMN health_log_hourly.max_temperature IS 'Maior temperatura (°C) nesta hora.';
COMMENT ON COLUMN health_log_hourly.avg_voltage IS 'Média de voltagem (V) nesta hora.';
COMMENT ON COLUMN health_log_hourly.sample_count IS 'Quantidade de amostras brutas que formaram este agregado.';

CREATE INDEX IF NOT EXISTS idx_health_log_hourly_mikrotik_hour
    ON health_log_hourly (mikrotik_id, hour_bucket DESC);

-- ─── health_log_daily ────────────────────────────────────────────────────────
-- Amostras diárias agregadas a partir de health_log_hourly.
-- Uma linha por mikrotik_id + dia. Retenção indefinida.
CREATE TABLE IF NOT EXISTS health_log_daily (
    id                BIGSERIAL    PRIMARY KEY,
    mikrotik_id       UUID         NOT NULL REFERENCES mikrotiks(id) ON DELETE CASCADE,
    day_bucket        DATE         NOT NULL,
    avg_cpu_load      NUMERIC(5,2),
    min_cpu_load      SMALLINT,
    max_cpu_load      SMALLINT,
    avg_memory_free   NUMERIC(14,2),
    avg_memory_total  NUMERIC(14,2),
    avg_temperature   NUMERIC(6,2),
    min_temperature   NUMERIC(5,2),
    max_temperature   NUMERIC(5,2),
    avg_voltage       NUMERIC(6,3),
    sample_count      INTEGER      NOT NULL DEFAULT 0,

    UNIQUE (mikrotik_id, day_bucket)
);

COMMENT ON TABLE  health_log_daily IS 'Agregação diária de métricas de saúde. Uma linha por dia por equipamento. Retenção indefinida.';
COMMENT ON COLUMN health_log_daily.day_bucket IS 'Data truncada para o início do dia (ex.: 2026-08-29).';
COMMENT ON COLUMN health_log_daily.avg_cpu_load IS 'Média do percentual de CPU neste dia.';
COMMENT ON COLUMN health_log_daily.min_cpu_load IS 'Menor valor de CPU neste dia.';
COMMENT ON COLUMN health_log_daily.max_cpu_load IS 'Maior valor de CPU neste dia.';
COMMENT ON COLUMN health_log_daily.avg_memory_free IS 'Média de memória livre (bytes) neste dia.';
COMMENT ON COLUMN health_log_daily.avg_memory_total IS 'Média de memória total (bytes) neste dia.';
COMMENT ON COLUMN health_log_daily.avg_temperature IS 'Média de temperatura (°C) neste dia.';
COMMENT ON COLUMN health_log_daily.min_temperature IS 'Menor temperatura (°C) neste dia.';
COMMENT ON COLUMN health_log_daily.max_temperature IS 'Maior temperatura (°C) neste dia.';
COMMENT ON COLUMN health_log_daily.avg_voltage IS 'Média de voltagem (V) neste dia.';
COMMENT ON COLUMN health_log_daily.sample_count IS 'Quantidade de amostras horárias que formaram este agregado.';

CREATE INDEX IF NOT EXISTS idx_health_log_daily_mikrotik_day
    ON health_log_daily (mikrotik_id, day_bucket DESC);

-- ─── Permissões para o usuário da aplicação ─────────────────────────────────
-- O app roda como mikrotik_watch, mas a migration pode ser executada como postgres.
GRANT ALL ON health_log_hourly TO mikrotik_watch;
GRANT ALL ON health_log_daily TO mikrotik_watch;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO mikrotik_watch;

-- ─── Inserir job de lock para o novo cron ────────────────────────────────────
INSERT INTO cron_locks (job_name) VALUES ('health_aggregate')
ON CONFLICT (job_name) DO NOTHING;
