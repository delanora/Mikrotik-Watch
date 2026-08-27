#!/bin/bash
#
# Mikrotik Watch - Script de Atualização
# Executa git pull e aplica migrations pendentes
#
set -euo pipefail

# ─── Cores ───────────────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# ─── Funções auxiliares ──────────────────────────────────────────────────────
info()    { echo -e "${BLUE}[INFO]${NC} $1"; }
success() { echo -e "${GREEN}[OK]${NC} $1"; }
warn()    { echo -e "${YELLOW}[WARN]${NC} $1"; }
error()   { echo -e "${RED}[ERRO]${NC} $1"; exit 1; }

# ─── Configurações ───────────────────────────────────────────────────────────
APP_DIR="/var/www/Mikrotik-Watch"
SERVICE_NAME="Mikrotik Watch"

# ─── Verificar root ──────────────────────────────────────────────────────────
if [[ $EUID -ne 0 ]]; then
    error "Este script deve ser executado como root (sudo ./update.sh)"
fi

# ─── Verificar diretório ─────────────────────────────────────────────────────
if [[ ! -d "$APP_DIR" ]]; then
    error "Diretório do projeto não encontrado: $APP_DIR"
fi

cd "$APP_DIR"

# ─── 1. Backup do .env ──────────────────────────────────────────────────────
info "Criando backup do arquivo .env..."
if [[ -f ".env" ]]; then
    cp .env ".env.backup.$(date +%Y%m%d_%H%M%S)"
    success "Backup do .env criado."
else
    warn "Arquivo .env não encontrado. Pule para a configuração manual."
fi

# ─── 2. Git pull ─────────────────────────────────────────────────────────────
info "Atualizando código via git..."

CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "main")
git fetch origin "$CURRENT_BRANCH"
git pull origin "$CURRENT_BRANCH"

success "Código atualizado."

# ─── 3. Instalar/atualizar dependências ──────────────────────────────────────
info "Atualizando dependências do Composer..."

if command -v composer &> /dev/null; then
    composer install --no-dev --optimize-autoloader --no-interaction
    success "Dependências atualizadas."
else
    warn "Composer não encontrado. Pulando instalação de dependências."
fi

# ─── 4. Executar migrations pendentes ────────────────────────────────────────
info "Verificando migrations pendentes..."

if [[ -f ".env" ]]; then
    # Extrair configurações do .env
    DB_HOST=$(grep -E '^DB_HOST=' .env | cut -d'=' -f2 | tr -d '"')
    DB_PORT=$(grep -E '^DB_PORT=' .env | cut -d'=' -f2 | tr -d '"')
    DB_NAME=$(grep -E '^DB_NAME=' .env | cut -d'=' -f2 | tr -d '"')
    DB_USER=$(grep -E '^DB_USER=' .env | cut -d'=' -f2 | tr -d '"')
    DB_PASS=$(grep -E '^DB_PASSWORD=' .env | cut -d'=' -f2 | tr -d '"')

    if [[ -d "database" ]]; then
        # Criar tabela de controle de migrations se não existir
        PGPASSWORD="${DB_PASS}" psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" -d "${DB_NAME}" -c "
            CREATE TABLE IF NOT EXISTS migrations (
                id SERIAL PRIMARY KEY,
                filename VARCHAR(255) NOT NULL UNIQUE,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        " 2>/dev/null || true

        MIGRATIONS_RUN=0
        for sql_file in $(ls database/*.sql 2>/dev/null | sort); do
            FILENAME=$(basename "$sql_file")

            # Verificar se a migration já foi aplicada
            ALREADY_APPLIED=$(PGPASSWORD="${DB_PASS}" psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" -d "${DB_NAME}" -tAc "
                SELECT COUNT(*) FROM migrations WHERE filename = '${FILENAME}';
            " 2>/dev/null || echo "0")

            if [[ "$ALREADY_APPLIED" == "0" ]]; then
                info "Aplicando migration: ${FILENAME}"
                PGPASSWORD="${DB_PASS}" psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" -d "${DB_NAME}" -f "$sql_file"

                # Registrar migration aplicada
                PGPASSWORD="${DB_PASS}" psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" -d "${DB_NAME}" -c "
                    INSERT INTO migrations (filename) VALUES ('${FILENAME}');
                " 2>/dev/null || true

                MIGRATIONS_RUN=$((MIGRATIONS_RUN + 1))
            fi
        done

        if [[ $MIGRATIONS_RUN -gt 0 ]]; then
            success "${MIGRATIONS_RUN} migration(s) aplicada(s)."
        else
            success "Nenhuma migration pendente."
        fi
    else
        warn "Diretório database/ não encontrado."
    fi
else
    warn "Arquivo .env não encontrado. Pulando migrations."
fi

# ─── 5. Limpar cache de autoload ────────────────────────────────────────────
info "Regenerando autoload..."
if [[ -f "vendor/autoload.php" ]]; then
    composer dump-autoload --optimize 2>/dev/null || true
fi

# ─── 6. Reiniciar serviço ────────────────────────────────────────────────────
info "Reiniciando serviço..."
systemctl restart "${SERVICE_NAME}" 2>/dev/null || warn "Serviço não encontrado. Reinicie manualmente."

# ─── 7. Ajustar permissões ───────────────────────────────────────────────────
info "Ajustando permissões..."
chown -R www-data:www-data "$APP_DIR"
chmod 600 "$APP_DIR/.env" 2>/dev/null || true

# ─── Resumo ──────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║              Atualização concluída com sucesso!             ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${BLUE}Projeto:${NC}   ${APP_DIR}"
echo -e "  ${BLUE}Branch:${NC}    ${CURRENT_BRANCH}"
echo -e "  ${BLUE}Status:${NC}    $(systemctl is-active "${SERVICE_NAME}" 2>/dev/null || echo 'verificar manualmente')"
echo ""
