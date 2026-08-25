#!/bin/bash
#
# Mikrotik Watch - Script de Instalação Automatizada
# Compatível com Debian 12+ / Ubuntu 22.04+
#
set -euo pipefail

# ─── Cores ───────────────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# ─── Configurações ───────────────────────────────────────────────────────────
APP_NAME="Mikrotik Watch"
APP_DIR="/var/www/Mikrotik Watch"
DB_NAME="mikrotik_watch_db"
DB_USER="mikrotik_watch"
DB_PASS=$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 20)
APP_PORT=8080
APP_SECRET=$(openssl rand -hex 32)

# ─── Funções auxiliares ──────────────────────────────────────────────────────
info()    { echo -e "${BLUE}[INFO]${NC} $1"; }
success() { echo -e "${GREEN}[OK]${NC} $1"; }
warn()    { echo -e "${YELLOW}[WARN]${NC} $1"; }
error()   { echo -e "${RED}[ERRO]${NC} $1"; exit 1; }

# ─── Verificar root ──────────────────────────────────────────────────────────
if [[ $EUID -ne 0 ]]; then
    error "Este script deve ser executado como root (sudo ./install.sh)"
fi

# ─── 1. Detectar SO e instalar dependências ──────────────────────────────────
info "Etapa 1/7: Verificando sistema operacional e instalando dependências..."

if command -v apt-get &> /dev/null; then
    info "Sistema baseado em Debian/Ubuntu detectado."
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq \
        git \
        php8.2-cli \
        php8.2-pgsql \
        php8.2-curl \
        php8.2-mbstring \
        php8.2-xml \
        postgresql \
        postgresql-client \
        openssl \
        curl
else
    error "Sistema operacional não suportado. Use Debian ou Ubuntu."
fi

success "Dependências instaladas."

# ─── 2. Verificar/instalar PHP 8.4+ ──────────────────────────────────────────
info "Etapa 2/7: Verificando versão do PHP..."

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;')

if [[ "$PHP_MAJOR" -lt 8 ]] || [[ "$PHP_MAJOR" -eq 8 && "$PHP_MINOR" -lt 2 ]]; then
    warn "PHP $PHP_VERSION detectado. Recomendado PHP 8.4+."
    warn "Continuando com a versão disponível..."
else
    success "PHP $PHP_VERSION detectado."
fi

# ─── 3. Clonar ou verificar o projeto ────────────────────────────────────────
info "Etapa 3/7: Verificando diretório do projeto..."

if [[ -d "$APP_DIR" ]]; then
    info "Diretório $APP_DIR já existe. Assumindo que o projeto já foi clonado."
    cd "$APP_DIR"
    git pull --ff-only 2>/dev/null || warn "Não foi possível atualizar via git."
else
    info "Clonando repositório em $APP_DIR..."
    if [[ -n "${GIT_REPO_URL:-}" ]]; then
        git clone "$GIT_REPO_URL" "$APP_DIR"
    else
        warn "Variável GIT_REPO_URL não definida."
        warn "Copie o projeto manualmente para $APP_DIR ou defina GIT_REPO_URL."
        mkdir -p "$APP_DIR"
    fi
    cd "$APP_DIR"
fi

success "Projeto encontrado em $APP_DIR."

# ─── 4. Criar usuário e banco PostgreSQL ─────────────────────────────────────
info "Etapa 4/7: Configurando PostgreSQL..."

# Iniciar serviço PostgreSQL se não estiver rodando
systemctl start postgresql 2>/dev/null || service postgresql start 2>/dev/null || true

# Criar usuário e banco
sudo -u postgres psql -tc "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'" | grep -q 1 || \
    sudo -u postgres psql -c "CREATE USER ${DB_USER} WITH PASSWORD '${DB_PASS}';"

sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" | grep -q 1 || \
    sudo -u postgres psql -c "CREATE DATABASE ${DB_NAME} OWNER ${DB_USER};"

sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE ${DB_NAME} TO ${DB_USER};"
sudo -u postgres psql -d "${DB_NAME}" -c "GRANT ALL ON SCHEMA public TO ${DB_USER};"

success "Banco de dados '${DB_NAME}' e usuário '${DB_USER}' criados."

# ─── 5. Rodar scripts de schema ─────────────────────────────────────────────
info "Etapa 5/7: Executando scripts de schema do banco de dados..."

if [[ -d "database" ]]; then
    for sql_file in $(ls database/*.sql 2>/dev/null | sort); do
        info "Executando: $(basename "$sql_file")"
        PGPASSWORD="${DB_PASS}" psql -h 127.0.0.1 -U "${DB_USER}" -d "${DB_NAME}" -f "$sql_file"
    done
    success "Schema do banco de dados aplicado."
else
    warn "Diretório database/ não encontrado. Pulando migrações."
fi

# ─── 6. Configurar .env ──────────────────────────────────────────────────────
info "Etapa 6/7: Configurando arquivo .env..."

if [[ ! -f ".env" ]]; then
    if [[ -f ".env.example" ]]; then
        cp .env.example .env

        # Substituir valores no .env
        sed -i "s|DB_HOST=.*|DB_HOST=127.0.0.1|g" .env
        sed -i "s|DB_PORT=.*|DB_PORT=5432|g" .env
        sed -i "s|DB_NAME=.*|DB_NAME=${DB_NAME}|g" .env
        sed -i "s|DB_USER=.*|DB_USER=${DB_USER}|g" .env
        sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|g" .env
        sed -i "s|APP_PORT=.*|APP_PORT=${APP_PORT}|g" .env
        sed -i "s|APP_SECRET=.*|APP_SECRET=${APP_SECRET}|g" .env
        sed -i "s|APP_URL=.*|APP_URL=http://localhost:${APP_PORT}|g" .env

        # Permissões restritivas
        chmod 600 .env

        success "Arquivo .env criado com credenciais geradas."
    else
        warn "Arquivo .env.example não encontrado. Criando .env vazio."
        touch .env
    fi
else
    warn "Arquivo .env já existe. Mantendo configuração atual."
fi

# ─── 7. Configurar serviço systemd ───────────────────────────────────────────
info "Etapa 7/7: Configurando serviço systemd..."

SERVICE_FILE="/etc/systemd/system/Mikrotik Watch.service"

cat > "${SERVICE_FILE}" << SVCEOF
[Unit]
Description=Mikrotik Watch - Painel de Monitoramento Mikrotik
After=network.target postgresql.service
Wants=postgresql.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=${APP_DIR}/src
ExecStart=$(which php) -S 0.0.0.0:${APP_PORT} -t ${APP_DIR}/src
Restart=on-failure
RestartSec=5
StandardOutput=journal
StandardError=journal

# Segurança
NoNewPrivileges=true
ProtectSystem=strict
ReadWritePaths=${APP_DIR}

[Install]
WantedBy=multi-user.target
SVCEOF

# Ajustar permissões do diretório
chown -R www-data:www-data "$APP_DIR"
chmod -R 755 "$APP_DIR"
chmod 600 "$APP_DIR/.env" 2>/dev/null || true

# Habilitar e iniciar serviço
systemctl daemon-reload
systemctl enable "Mikrotik Watch.service" 2>/dev/null || true
systemctl restart "Mikrotik Watch.service" 2>/dev/null || true

success "Serviço systemd configurado e iniciado."

# ─── Resumo final ────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║              Instalação concluída com sucesso!              ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${BLUE}URL de acesso:${NC}  http://localhost:${APP_PORT}"
echo -e "  ${BLUE}Diretório:${NC}      ${APP_DIR}"
echo ""
echo -e "  ${YELLOW}Banco de dados:${NC}"
echo -e "    Nome:     ${DB_NAME}"
echo -e "    Usuário:  ${DB_USER}"
echo -e "    Senha:    ${DB_PASS}"
echo -e "    Host:     127.0.0.1:5432"
echo ""
echo -e "  ${YELLOW}Credenciais padrão:${NC}"
echo -e "    Login:    admin"
echo -e "    Senha:    admin"
echo -e "    ${RED}(Altere a senha após o primeiro login!)${NC}"
echo ""
echo -e "  ${YELLOW}Comandos úteis:${NC}"
echo -e "    Status:   systemctl status 'Mikrotik Watch'"
echo -e "    Logs:     journalctl -u 'Mikrotik Watch' -f"
echo -e "    Reiniciar: systemctl restart 'Mikrotik Watch'"
echo ""
echo -e "  ${BLUE}Gerenciar com:${NC}"
echo -e "    ./update.sh   # Atualizar projeto"
echo ""
