-- =============================================================================
-- Mikrotik Watch - Permissões do Usuário da Aplicação
--
-- Executa GRANT de todas as permissões necessárias para o usuário
-- mikrotik_watch no banco de dados mikrotik_watch_db.
--
-- Uso:
--   psql -h 127.0.0.1 -U postgres -d mikrotik_watch_db \
--       -f database/grant_permissions.sql
--
-- Ou via script:
--   sudo -u postgres psql -d mikrotik_watch_db \
--       -f database/grant_permissions.sql
-- =============================================================================

-- Permissões no banco de dados
GRANT ALL PRIVILEGES ON DATABASE mikrotik_watch_db TO mikrotik_watch;

-- Permissões no schema
GRANT ALL ON SCHEMA public TO mikrotik_watch;

-- Permissões em todas as tabelas existentes
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO mikrotik_watch;

-- Permissões em todas as sequences existentes
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO mikrotik_watch;

-- Permissões em funções (se existirem)
GRANT ALL PRIVILEGES ON ALL FUNCTIONS IN SCHEMA public TO mikrotik_watch;

-- Permissões para tabelas futuras (ALTER DEFAULT)
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT ALL ON TABLES TO mikrotik_watch;

ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT ALL ON SEQUENCES TO mikrotik_watch;
