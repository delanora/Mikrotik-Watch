#!/bin/bash
#
# Mikrotik Watch - Script de Instalação Automatizada
# Compatível com Debian 13+ / Ubuntu 24.04+
#
# Requisitos:
#   - Executar como root
#
# Debian 13:
#   - PHP 8.4 (versão nativa)
#
set -euo pipefail

# ─── Cores ───────────────────────────────────────────────────────────────────

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# ─── Configurações ───────────────────────────────────────────────────────────

APP_NAME="Mikrotik Watch"
APP_DIR="/var/www/Mikrotik Watch"

DB_NAME="mikrotik_watch_db"
DB_USER="mikrotik_watch"

# Gera uma senha sem depender de pipeline/head
DB_PASS=$(openssl rand -hex 20)

APP_PORT=8080
APP_SECRET=$(openssl rand -hex 32)

SERVICE_NAME="Mikrotik Watch"
SERVICE_FILE="/etc/systemd/system/Mikrotik-Watch.service"

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
    error "Este script deve ser executado como root."
fi

# ─── Detectar distribuição ───────────────────────────────────────────────────

info "Detectando sistema operacional..."

if [[ ! -f /etc/os-release ]]; then
    error "Não foi possível identificar o sistema operacional."
fi

source /etc/os-release

info "Sistema detectado: ${PRETTY_NAME}"

if [[ "${ID:-}" != "debian" && "${ID_LIKE:-}" != *"debian"* ]]; then
    error "Sistema operacional não suportado. Use Debian ou Ubuntu."
fi

success "Sistema operacional compatível."

# ─── 1. Instalar dependências ────────────────────────────────────────────────

info "Etapa 1/7: Atualizando sistema e instalando dependências..."

export DEBIAN_FRONTEND=noninteractive

apt-get update

apt-get install -y \
    git \
    curl \
    ca-certificates \
    openssl \
    postgresql \
    postgresql-client \
    php-cli \
    php-pgsql \
    php-curl \
    php-mbstring \
    php-xml \
    php-zip \
    unzip

success "Dependências instaladas."

# ─── 2. Verificar PHP ────────────────────────────────────────────────────────

info "Etapa 2/7: Verificando versão do PHP..."

if ! command -v php >/dev/null 2>&1; then
    error "PHP não foi instalado corretamente."
fi

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
PHP_FULL_VERSION=$(php -v | head -n 1)

PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;')

echo "    ${PHP_FULL_VERSION}"

if [[ "$PHP_MAJOR" -lt 8 ]] || \
   [[ "$PHP_MAJOR" -eq 8 && "$PHP_MINOR" -lt 2 ]]; then
    error "PHP ${PHP_VERSION} detectado. O projeto requer PHP 8.2 ou superior."
fi

success "PHP ${PHP_VERSION} detectado e compatível."

# ─── 3. Instalar Composer ────────────────────────────────────────────────────

info "Etapa 3/7: Verificando Composer..."

if ! command -v composer >/dev/null 2>&1; then

    info "Composer não encontrado. Instalando..."

    EXPECTED_SIGNATURE="$(curl -fsSL https://composer.github.io/installer.sig)"

    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"

    ACTUAL_SIGNATURE="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

    if [[ "$EXPECTED_SIGNATURE" != "$ACTUAL_SIGNATURE" ]]; then
        rm -f composer-setup.php
        error "Falha na verificação de integridade do instalador do Composer."
    fi

    php composer-setup.php --install-dir=/usr/local/bin --filename=composer

    rm -f composer-setup.php

    success "Composer instalado."
else
    success "Composer já está instalado."
fi

composer --version

# ─── 4. Clonar ou verificar projeto ──────────────────────────────────────────

info "Etapa 4/7: Verificando diretório do projeto..."

if [[ -d "$APP_DIR/.git" ]]; then

    info "Projeto Git encontrado em:"
    echo "    $APP_DIR"

    cd "$APP_DIR"

    git pull --ff-only 2>/dev/null || \
        warn "Não foi possível atualizar via git. Mantendo versão atual."

elif [[ -d "$APP_DIR" && "$(find "$APP_DIR" -mindepth 1 -maxdepth 1 | head -n 1)" ]]; then

    warn "O diretório $APP_DIR já existe e não parece ser um repositório Git."
    info "Assumindo que o projeto já foi copiado manualmente."

    cd "$APP_DIR"

else

    info "Diretório do projeto não encontrado."

    GIT_REPO="${GIT_REPO_URL:-https://github.com/delanora/Mikrotik-Watch.git}"

    info "Clonando repositório: ${GIT_REPO}"

    mkdir -p "$(dirname "$APP_DIR")"

    git clone "$GIT_REPO" "$APP_DIR"

    cd "$APP_DIR"
fi

success "Projeto localizado em $APP_DIR."

# ─── Instalar dependências PHP do projeto ────────────────────────────────────

if [[ -f "composer.json" ]]; then

    info "composer.json encontrado."

    info "Instalando dependências PHP..."

    composer install \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

    success "Dependências PHP instaladas."

else

    warn "composer.json não encontrado. Pulando composer install."

fi

# ─── 5. Configurar PostgreSQL ────────────────────────────────────────────────

info "Etapa 5/7: Configurando PostgreSQL..."

# Tentar iniciar PostgreSQL
if command -v systemctl >/dev/null 2>&1 && \
   systemctl is-system-running >/dev/null 2>&1; then

    systemctl enable postgresql >/dev/null 2>&1 || true
    systemctl start postgresql >/dev/null 2>&1 || true

else

    service postgresql start >/dev/null 2>&1 || true

fi

# Verificar se PostgreSQL está respondendo
if ! pg_isready -q; then
    error "PostgreSQL não está disponível."
fi

success "PostgreSQL está funcionando."

# Executar setup do banco via script dedicado
info "Executando database/setup.sh..."

DB_NAME="${DB_NAME}" DB_USER="${DB_USER}" DB_PASS="${DB_PASS}" \
    bash "$(dirname "$0")/database/setup.sh"

# ─── 6. Configurar .env ─────────────────────────────────────────────────────

info "Etapa 6/7: Configurando arquivo .env..."

# ─── Configurar .env ─────────────────────────────────────────────────────────

if [[ ! -f ".env" ]]; then

    if [[ -f ".env.example" ]]; then

        cp .env.example .env

        # DB
        sed -i "s|^DB_HOST=.*|DB_HOST=127.0.0.1|g" .env
        sed -i "s|^DB_PORT=.*|DB_PORT=5432|g" .env
        sed -i "s|^DB_NAME=.*|DB_NAME=${DB_NAME}|g" .env
        sed -i "s|^DB_USER=.*|DB_USER=${DB_USER}|g" .env
        sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|g" .env

        # Aplicação
        sed -i "s|^APP_PORT=.*|APP_PORT=${APP_PORT}|g" .env
        sed -i "s|^APP_SECRET=.*|APP_SECRET=${APP_SECRET}|g" .env
        sed -i "s|^APP_URL=.*|APP_URL=http://localhost:${APP_PORT}|g" .env

        # Criptografia de credenciais Mikrotik
        CRED_KEY=$(php -r "echo base64_encode(random_bytes(32));")
        sed -i "s|^CREDENTIAL_ENCRYPTION_KEY=.*|CREDENTIAL_ENCRYPTION_KEY=${CRED_KEY}|g" .env

        chmod 600 .env

        success "Arquivo .env criado."

    else

        warn ".env.example não encontrado."

        touch .env
        chmod 600 .env

    fi

else

    warn ".env já existe. Mantendo configuração atual."

fi

# ─── 7. Configurar crontab ────────────────────────────────────────────────

info "Etapa 7/8: Configurando crontab para coletas..."

mkdir -p /var/log/mikrotik-watch

# Adicionar crontabs apenas se não existirem
CRON_COLLECT="* * * * * cd '${APP_DIR}/src' && php cron/collect.php >> /var/log/mikrotik-watch/cron.log 2>&1"
CRON_NETWATCH="* * * * * cd '${APP_DIR}/src' && php cron/collect_netwatch.php >> /var/log/mikrotik-watch/cron.log 2>&1"
CRON_PING="*/5 * * * * cd '${APP_DIR}/src' && php cron/collect_ping.php >> /var/log/mikrotik-watch/cron.log 2>&1"

(crontab -l 2>/dev/null | grep -v 'collect.php' | grep -v 'collect_netwatch.php' | grep -v 'collect_ping.php'; echo "$CRON_COLLECT"; echo "$CRON_NETWATCH"; echo "$CRON_PING") | crontab -

success "Crontab configurado."

# ─── 8. Configurar aplicação ────────────────────────────────────────────────

info "Etapa 8/8: Configurando aplicação e serviço..."

# Verificar diretório src
if [[ ! -d "${APP_DIR}/src" ]]; then
    warn "Diretório ${APP_DIR}/src não encontrado."
    warn "O serviço não poderá iniciar até que esse diretório exista."
fi

# ─── Permissões ──────────────────────────────────────────────────────────────

if id "www-data" >/dev/null 2>&1; then

    chown -R www-data:www-data "$APP_DIR"

    find "$APP_DIR" -type d -exec chmod 755 {} \;
    find "$APP_DIR" -type f -exec chmod 644 {} \;

    if [[ -f "${APP_DIR}/.env" ]]; then
        chmod 600 "${APP_DIR}/.env"
    fi

    success "Permissões configuradas."

else

    warn "Usuário www-data não encontrado."

fi

# ─── Systemd ─────────────────────────────────────────────────────────────────

SYSTEMD_AVAILABLE=false

if command -v systemctl >/dev/null 2>&1; then

    if [[ "$(ps -p 1 -o comm= 2>/dev/null)" == "systemd" ]]; then
        SYSTEMD_AVAILABLE=true
    fi

fi

if [[ "$SYSTEMD_AVAILABLE" == true ]]; then

    cat > "${SERVICE_FILE}" <<EOF
[Unit]
Description=Mikrotik Watch - Painel de Monitoramento Mikrotik
After=network-online.target postgresql.service
Wants=network-online.target postgresql.service

[Service]
Type=simple

User=www-data
Group=www-data

WorkingDirectory=${APP_DIR}/src

ExecStart=/usr/bin/php -S 0.0.0.0:${APP_PORT} -t ${APP_DIR}/src

Restart=on-failure
RestartSec=5

StandardOutput=journal
StandardError=journal

# Segurança
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=${APP_DIR}

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload

    systemctl enable "${SERVICE_FILE}" >/dev/null 2>&1 || true

    systemctl restart "${SERVICE_FILE}" || \
        warn "Não foi possível iniciar o serviço automaticamente."

    success "Serviço systemd configurado."

else

    warn "systemd não está ativo."

    warn "Isso é comum em instalações WSL sem systemd habilitado."

    warn "A aplicação não será registrada como serviço automaticamente."

    info "Para iniciar manualmente:"
    echo "    cd '${APP_DIR}/src'"
    echo "    php -S 0.0.0.0:${APP_PORT} -t ."

fi

# ─── Testar aplicação ───────────────────────────────────────────────────────

if [[ "$SYSTEMD_AVAILABLE" == true ]]; then

    sleep 2

    if systemctl is-active --quiet "${SERVICE_FILE}"; then

        success "Mikrotik Watch está rodando."

    else

        warn "O serviço não está ativo."

        info "Verifique os logs com:"
        echo "    journalctl -u '${SERVICE_FILE}' -n 100 --no-pager"

    fi

fi

# ─── Resumo ──────────────────────────────────────────────────────────────────

echo ""

echo -e "${GREEN}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║              Instalação concluída!                          ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════════╝${NC}"

echo ""

echo -e "  ${BLUE}Sistema:${NC}"
echo -e "    ${PRETTY_NAME}"

echo ""

echo -e "  ${BLUE}PHP:${NC}"
echo -e "    ${PHP_FULL_VERSION}"

echo ""

echo -e "  ${BLUE}URL de acesso:${NC}"
echo -e "    http://localhost:${APP_PORT}"

echo ""

echo -e "  ${BLUE}Diretório:${NC}"
echo -e "    ${APP_DIR}"

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

if [[ "$SYSTEMD_AVAILABLE" == true ]]; then

    echo -e "  ${YELLOW}Comandos úteis:${NC}"
    echo "    Status:    systemctl status '${SERVICE_FILE}'"
    echo "    Logs:      journalctl -u '${SERVICE_FILE}' -f"
    echo "    Reiniciar: systemctl restart '${SERVICE_FILE}'"
    echo "    Parar:     systemctl stop '${SERVICE_FILE}'"

else

    echo -e "  ${YELLOW}Executar manualmente:${NC}"
    echo "    cd '${APP_DIR}/src'"
    echo "    php -S 0.0.0.0:${APP_PORT} -t ."

fi

echo ""

echo -e "  ${BLUE}Atualizar projeto:${NC}"
echo "    cd '${APP_DIR}'"
echo "    git pull"

echo ""

echo -e "${GREEN}Instalação finalizada.${NC}"

