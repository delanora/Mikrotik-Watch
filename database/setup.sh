#!/bin/bash
#
# Mikrotik Watch - Setup do Banco de Dados
#
# Script standalone para criar usuário, banco de dados, aplicar schema
# e configurar permissões. Pode ser executado independentemente do install.sh.
#
# Requisitos:
#   - Executar como root ou sudo
#   - PostgreSQL rodando e acessível
#
# Uso:
#   sudo ./database/setup.sh
#
# Variáveis de ambiente opcionais:
#   DB_NAME     - Nome do banco (padrão: mikrotik_watch_db)
#   DB_USER     - Nome do usuário (padrão: mikrotik_watch)
#   DB_PASS     - Senha do usuário (gera aleatória se não informada)
#   DB_HOST     - Host do PostgreSQL (padrão: 127.0.0.1)
#   DB_PORT     - Porta do PostgreSQL (padrão: 5432)
#

set -euo pipefail

# ─── Cores ───────────────────────────────────────────────────────────────────

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# ─── Configurações ───────────────────────────────────────────────────────────

DB_NAME="${DB_NAME:-mikrotik_watch_db}"
DB_USER="${DB_USER:-mikrotik_watch}"
DB_PASS="${DB_PASS:-$(openssl rand -hex 20)}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

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

info "Verificando PostgreSQL..."

if ! pg_isready -h "$DB_HOST" -p "$DB_PORT" -q 2>/dev/null; then
    error "PostgreSQL não está disponível em {$DB_HOST}:{$DB_PORT}."
fi

success "PostgreSQL está funcionando."

# ─── 1. Criar usuário PostgreSQL ─────────────────────────────────────────────

info "Etapa 1/4: Configurando usuário PostgreSQL..."

if ! runuser -u postgres -- psql -tAc \
    "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'" | grep -q 1; then

    info "Criando usuário '${DB_USER}'..."
    runuser -u postgres -- psql -c \
        "CREATE USER ${DB_USER} WITH PASSWORD '${DB_PASS}';"

else

    info "Usuário '${DB_USER}' já existe. Atualizando senha..."
    runuser -u postgres -- psql -c \
        "ALTER USER ${DB_USER} WITH PASSWORD '${DB_PASS}';"

fi

success "Usuário '${DB_USER}' configurado."

# ─── 2. Criar banco de dados ─────────────────────────────────────────────────

info "Etapa 2/4: Criando banco de dados..."

if ! runuser -u postgres -- psql -tAc \
    "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" | grep -q 1; then

    info "Criando banco '${DB_NAME}'..."
    runuser -u postgres -- psql -c \
        "CREATE DATABASE ${DB_NAME} OWNER ${DB_USER};"

else

    info "Banco '${DB_NAME}' já existe."

fi

success "Banco '${DB_NAME}' configurado."

# ─── 3. Aplicar schema ──────────────────────────────────────────────────────

info "Etapa 3/4: Aplicando schema..."

SCHEMA_FILE="${SCRIPT_DIR}/init.sql"

if [[ ! -f "$SCHEMA_FILE" ]]; then
    error "Arquivo de schema não encontrado: ${SCHEMA_FILE}"
fi

PGPASSWORD="${DB_PASS}" \
    psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
    -f "$SCHEMA_FILE"

success "Schema aplicado com sucesso."

# ─── 4. Conceder permissões ──────────────────────────────────────────────────

info "Etapa 4/4: Concedendo permissões..."

PERMISSIONS_FILE="${SCRIPT_DIR}/grant_permissions.sql"

if [[ -f "$PERMISSIONS_FILE" ]]; then
    runuser -u postgres -- psql -d "$DB_NAME" -f "$PERMISSIONS_FILE"
else
    # Fallback: conceder permissões diretamente
    runuser -u postgres -- psql -d "$DB_NAME" -c "
        GRANT ALL PRIVILEGES ON DATABASE ${DB_NAME} TO ${DB_USER};
        GRANT ALL ON SCHEMA public TO ${DB_USER};
        GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO ${DB_USER};
        GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO ${DB_USER};
    "
fi

success "Permissões configuradas."

# ─── Resumo ──────────────────────────────────────────────────────────────────

echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║           Setup do banco de dados concluído!                ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${BLUE}Banco:${NC}     ${DB_NAME}"
echo -e "  ${BLUE}Usuário:${NC}   ${DB_USER}"
echo -e "  ${BLUE}Senha:${NC}     ${DB_PASS}"
echo -e "  ${BLUE}Host:${NC}      ${DB_HOST}:${DB_PORT}"
echo ""
echo -e "  ${YELLOW}Para testar a conexão:${NC}"
echo "    PGPASSWORD='${DB_PASS}' psql -h ${DB_HOST} -U ${DB_USER} -d ${DB_NAME} -c '\\dt'"
echo ""
echo -e "  ${YELLOW}Para atualizar o .env:${NC}"
echo "    DB_PASSWORD=${DB_PASS}"
echo ""
