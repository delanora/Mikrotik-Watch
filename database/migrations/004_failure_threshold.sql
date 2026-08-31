-- =============================================================================
-- Migration 004: Debounce de Status (Failure Threshold)
--
-- Implementa mecanismo de confirmação por falhas consecutivas para evitar
-- falsos positivos de status offline. Adiciona:
--   - consecutive_failures: contador de falhas consecutivas
--   - first_failure_at: timestamp da primeira falha da sequência
--   - Status 'warning' como estado intermediário
-- =============================================================================

-- ─── mikrotiks: novas colunas ───────────────────────────────────────────────

ALTER TABLE mikrotiks ADD COLUMN IF NOT EXISTS consecutive_failures INTEGER NOT NULL DEFAULT 0;
ALTER TABLE mikrotiks ADD COLUMN IF NOT EXISTS first_failure_at TIMESTAMPTZ NULL;

COMMENT ON COLUMN mikrotiks.consecutive_failures IS 'Contador de falhas consecutivas desde o último sucesso. Zerado a cada verificação bem-sucedida.';
COMMENT ON COLUMN mikrotiks.first_failure_at IS 'Timestamp da primeira falha da sequência atual. Usado para registrar o momento real do problema (não o momento da confirmação). Limpo a cada sucesso.';

-- Atualizar CHECK constraint de current_status para aceitar 'warning'
-- Primeiro, localizar o nome da constraint gerada automaticamente
DO $$
DECLARE
    constraint_name TEXT;
BEGIN
    -- Buscar constraint CHECK existente em mikrotiks
    SELECT con.conname INTO constraint_name
    FROM pg_constraint con
    JOIN pg_class rel ON rel.oid = con.conrelid
    JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
    WHERE rel.relname = 'mikrotiks'
      AND con.contype = 'c'
      AND pg_get_constraintdef(con.oid) LIKE '%current_status%'
    LIMIT 1;

    IF constraint_name IS NOT NULL THEN
        EXECUTE format('ALTER TABLE mikrotiks DROP CONSTRAINT %I', constraint_name);
    END IF;
END $$;

ALTER TABLE mikrotiks ADD CONSTRAINT mikrotiks_current_status_check
    CHECK (current_status IN ('online', 'offline', 'unknown', 'warning'));

-- ─── netwatch_hosts: novas colunas ──────────────────────────────────────────

ALTER TABLE netwatch_hosts ADD COLUMN IF NOT EXISTS consecutive_failures INTEGER NOT NULL DEFAULT 0;
ALTER TABLE netwatch_hosts ADD COLUMN IF NOT EXISTS first_failure_at TIMESTAMPTZ NULL;

COMMENT ON COLUMN netwatch_hosts.consecutive_failures IS 'Contador de falhas consecutivas desde o último sucesso. Zerado a cada verificação bem-sucedida.';
COMMENT ON COLUMN netwatch_hosts.first_failure_at IS 'Timestamp da primeira falha da sequência atual. Usado para registrar o momento real do problema (não o momento da confirmação). Limpo a cada sucesso.';

-- Atualizar CHECK constraint de current_status para aceitar 'warning'
DO $$
DECLARE
    constraint_name TEXT;
BEGIN
    SELECT con.conname INTO constraint_name
    FROM pg_constraint con
    JOIN pg_class rel ON rel.oid = con.conrelid
    JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
    WHERE rel.relname = 'netwatch_hosts'
      AND con.contype = 'c'
      AND pg_get_constraintdef(con.oid) LIKE '%current_status%'
    LIMIT 1;

    IF constraint_name IS NOT NULL THEN
        EXECUTE format('ALTER TABLE netwatch_hosts DROP CONSTRAINT %I', constraint_name);
    END IF;
END $$;

ALTER TABLE netwatch_hosts ADD CONSTRAINT netwatch_hosts_current_status_check
    CHECK (current_status IN ('up', 'down', 'unknown', 'warning'));
