#!/bin/bash
#
# Mikrotik Watch - Setup do Banco de Dados de Testes
#
# Cria o banco de dados de testes (mikrotik_watch_test) com o schema completo.
# Reutiliza o mesmo init.sql do banco de produção.
#
# Uso:
#   sudo ./database/setup_test.sh
#
# Variáveis de ambiente opcionais:
#   TEST_DB_NAME  - Nome do banco de teste (padrão: mikrotik_watch_test)
#   TEST_DB_HOST  - Host do PostgreSQL (padrão: 127.0.0.1)
#   TEST_DB_PORT  - Porta do PostgreSQL (padrão: 5432)
#   DB_USER       - Usuário PostgreSQL (padrão: mikrotik_watch)
#

set -euo pipefail

# ─── Cores ───────────────────────────────────────────────────────────────────

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# ─── Configurações ───────────────────────────────────────────────────────────

TEST_DB_NAME="${TEST_DB_NAME:-mikrotik_watch_test}"
DB_USER="${DB_USER:-mikrotik_watch}"
TEST_DB_HOST="${TEST_DB_HOST:-127.0.0.1}"
TEST_DB_PORT="${TEST_DB_PORT:-5432}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ─── Funções auxiliares ──────────────────────────────────────────────────────

info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

success() {
    echo -e "${GREEN}[OK]${NC} $1"
}

warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

error() {
    echo -e "${RED}[ERRO]${NC} $1"
    exit 1
}

# ─── Verificar root ──────────────────────────────────────────────────────────

if [[ $EUID -ne 0 ]]; then
    error "Este script deve ser executado como root ou via sudo."
fi

# ─── Verificar PostgreSQL ────────────────────────────────────────────────────

if ! pg_isready -h "$TEST_DB_HOST" -p "$TEST_DB_PORT" -q 2>/dev/null; then
    error "PostgreSQL não está disponível."
fi

# ─── Recriar banco de testes ────────────────────────────────────────────────

info "Configurando banco de testes '${TEST_DB_NAME}'..."

# Encerrar conexões ativas
runuser -u postgres -- psql -c \
    "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '${TEST_DB_NAME}' AND pid <> pg_backend_pid();" \
    2>/dev/null || true

# Remover banco existente
if runuser -u postgres -- psql -tAc \
    "SELECT 1 FROM pg_database WHERE datname='${TEST_DB_NAME}'" | grep -q 1; then

    warn "Banco '${TEST_DB_NAME}' já existe. Recriando..."
    runuser -u postgres -- psql -c "DROP DATABASE \"${TEST_DB_NAME}\";"
fi

# Criar banco
runuser -u postgres -- psql -c "CREATE DATABASE \"${TEST_DB_NAME}\" OWNER ${DB_USER};"
success "Banco '${TEST_DB_NAME}' criado."

# ─── Aplicar schema ──────────────────────────────────────────────────────────

info "Aplicando schema..."

SCHEMA_FILE="${SCRIPT_DIR}/init.sql"

if [[ ! -f "$SCHEMA_FILE" ]]; then
    error "Arquivo de schema não encontrado: ${SCHEMA_FILE}"
fi

runuser -u postgres -- psql -d "${TEST_DB_NAME}" -f "$SCHEMA_FILE"

# ─── Conceder permissões ─────────────────────────────────────────────────────

info "Concedendo permissões..."

PERMISSIONS_FILE="${SCRIPT_DIR}/grant_permissions.sql"

if [[ -f "$PERMISSIONS_FILE" ]]; then
    runuser -u postgres -- psql -d "${TEST_DB_NAME}" -f "$PERMISSIONS_FILE"
else
    runuser -u postgres -- psql -d "${TEST_DB_NAME}" -c "
        GRANT ALL PRIVILEGES ON DATABASE ${TEST_DB_NAME} TO ${DB_USER};
        GRANT ALL ON SCHEMA public TO ${DB_USER};
        GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO ${DB_USER};
        GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO ${DB_USER};
    "
fi

# ─── Resumo ──────────────────────────────────────────────────────────────────

echo ""
success "Banco de testes configurado: ${TEST_DB_NAME}"
echo ""
echo -e "  ${YELLOW}Para rodar os testes:${NC}"
echo "    vendor/bin/phpunit --filter Integration"
echo ""
echo -e "  ${YELLOW}Variável de ambiente:${NC}"
echo "    TEST_DB_NAME=${TEST_DB_NAME}"
echo "    TEST_DB_HOST=${TEST_DB_HOST}"
echo "    TEST_DB_PORT=${TEST_DB_PORT}"
echo "    TEST_DB_USER=${DB_USER}"
echo ""
