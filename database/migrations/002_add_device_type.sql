-- =============================================================================
-- Migration 002: Suporte a equipamentos Ping (não-Mikrotik)
--
-- Adiciona device_type (mikrotik/ping) e last_rtt_ms na tabela mikrotiks.
-- Torna nullable os campos que só fazem sentido para device_type = 'mikrotik'.
-- NOTA: Este script é idempotente — pode ser executado múltiplas vezes sem erro.
-- =============================================================================

-- Adicionar colunas novas (idempotente)
ALTER TABLE mikrotiks ADD COLUMN IF NOT EXISTS device_type VARCHAR(20) NOT NULL DEFAULT 'mikrotik';
ALTER TABLE mikrotiks ADD COLUMN IF NOT EXISTS last_rtt_ms INTEGER;

-- Comentários
COMMENT ON COLUMN mikrotiks.device_type IS 'Tipo do equipamento: mikrotik (API REST) ou ping (ICMP).';
COMMENT ON COLUMN mikrotiks.last_rtt_ms IS 'Último tempo de resposta ICMP em ms (apenas para device_type = ping).';

-- Tornar nullable os campos específicos de Mikrotik (idempotente via DO $$)
DO $$ BEGIN
    ALTER TABLE mikrotiks ALTER COLUMN port DROP NOT NULL;
EXCEPTION WHEN undefined_column THEN NULL;
END $$;
DO $$ BEGIN
    ALTER TABLE mikrotiks ALTER COLUMN port SET DEFAULT 443;
EXCEPTION WHEN undefined_column THEN NULL;
END $$;
DO $$ BEGIN
    ALTER TABLE mikrotiks ALTER COLUMN use_ssl DROP NOT NULL;
EXCEPTION WHEN undefined_column THEN NULL;
END $$;
DO $$ BEGIN
    ALTER TABLE mikrotiks ALTER COLUMN use_ssl SET DEFAULT true;
EXCEPTION WHEN undefined_column THEN NULL;
END $$;
DO $$ BEGIN
    ALTER TABLE mikrotiks ALTER COLUMN username DROP NOT NULL;
EXCEPTION WHEN undefined_column THEN NULL;
END $$;
DO $$ BEGIN
    ALTER TABLE mikrotiks ALTER COLUMN password_encrypted DROP NOT NULL;
EXCEPTION WHEN undefined_column THEN NULL;
END $$;

-- CHECK constraint: mikrotik requer host, port, username, password_encrypted
ALTER TABLE mikrotiks DROP CONSTRAINT IF EXISTS chk_mikrotik_fields;
ALTER TABLE mikrotiks ADD CONSTRAINT chk_mikrotik_fields
    CHECK (
        (device_type = 'mikrotik' AND host IS NOT NULL AND port IS NOT NULL AND username IS NOT NULL AND password_encrypted IS NOT NULL)
        OR
        (device_type = 'ping' AND host IS NOT NULL)
    );

-- CHECK constraint: device_type só pode ser 'mikrotik' ou 'ping'
ALTER TABLE mikrotiks DROP CONSTRAINT IF EXISTS mikrotiks_device_type_check;
ALTER TABLE mikrotiks ADD CONSTRAINT mikrotiks_device_type_check
    CHECK (device_type IN ('mikrotik', 'ping'));

-- Garantir que equipamentos existentes continuam como 'mikrotik'
UPDATE mikrotiks SET device_type = 'mikrotik' WHERE device_type IS NULL;

-- Índice para buscar equipamentos ping
CREATE INDEX IF NOT EXISTS idx_mikrotiks_device_type ON mikrotiks (device_type);
