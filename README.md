# Mikrotik Watch

[![PHP](https://img.shields.io/badge/PHP-8.4+-777BB4?style=flat&logo=php&logoColor=white)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-17-4169E1?style=flat&logo=postgresql&logoColor=white)](https://www.postgresql.org/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Painel de monitoramento web para gestão de múltiplos equipamentos Mikrotik RouterOS. Coleta métricas de sistema, status de hosts via Netwatch e informações de equipamentos em tempo real.

![Mikrotik Watch Dashboard](https://via.placeholder.com/800x400/1e293b/f8fafc?text=Mikrotik+Watch+Dashboard)

## Funcionalidades

- 🖥️ **Dashboard** — Visão geral de todos os equipamentos com status offline
- 📊 **Métricas de Saúde** — CPU, memória, temperatura coletadas via API REST
- 🔌 **Multi-equipamento** — Gerencie N equipamentos Mikrotik em um único painel
- 🔐 **Autenticação** — Controle de acesso com perfis (admin/viewer)
- 📱 **Responsivo** — Interface adaptável para desktop e mobile
- ⚡ **Coleta Automática** — Cron jobs para coleta periódica de dados (a cada 1 minuto)
- 🔒 **Segurança** — Senhas criptografadas, prepared statements, CSRF tokens

## Pré-requisitos

- PHP 8.2+ (recomendado: 8.4)
- PostgreSQL 17+
- Extensões PHP: `pdo_pgsql`, `curl`, `mbstring`, `openssl`
- Composer
- Git

## Instalação Automática (Debian/Ubuntu)

```bash
# 1. Clone o repositório
git clone https://github.com/seu-usuario/mikrotik-watch.git
cd mikrotik-watch

# 2. Execute o script de instalação (requer root)
sudo ./install.sh
```

O script irá:
1. Detectar o sistema operacional e instalar dependências
2. Criar usuário e banco PostgreSQL
3. Executar os scripts de schema do banco
4. Configurar o arquivo `.env` com credenciais geradas
5. Criar e habilitar um serviço systemd
6. Exibir a URL de acesso e credenciais padrão

## Instalação Manual

### 1. Instalar dependências do sistema

**Debian/Ubuntu:**
```bash
sudo apt update
sudo apt install -y \
    php-cli php-pgsql php-curl php-mbstring php-xml \
    postgresql postgresql-client \
    git composer
```

**CentOS/RHEL/Fedora:**
```bash
sudo dnf install -y \
    php-cli php-pgsql php-curl php-mbstring php-xml \
    postgresql-server postgresql \
    git composer
```

### 2. Clonar o repositório

```bash
git clone https://github.com/seu-usuario/mikrotik-watch.git
cd mikrotik-watch
```

### 3. Instalar dependências PHP

```bash
composer install --no-dev --optimize-autoloader
```

### 4. Configurar o banco de dados

```bash
# Criar usuário e banco
sudo -u postgres psql -c "CREATE USER mikrotik_watch WITH PASSWORD 'sua_senha';"
sudo -u postgres psql -c "CREATE DATABASE mikrotik_watch_db OWNER mikrotik_watch;"
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE mikrotik_watch_db TO mikrotik_watch;"

# Executar schema
PGPASSWORD=sua_senha psql -h 127.0.0.1 -U mikrotik_watch -d mikrotik_watch_db -f database/init.sql
```

### 5. Configurar variáveis de ambiente

```bash
cp .env.example .env
```

Edite o arquivo `.env` com suas configurações:

```env
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=mikrotik_watch_db
DB_USER=mikrotik_watch
DB_PASSWORD=sua_senha
APP_URL=http://localhost:8080
APP_SECRET=gera_um_secreto_aqui
```

### 6. Iniciar o servidor de desenvolvimento

```bash
cd src
php -S 0.0.0.0:8080
```

Acesse: `http://localhost:8080`

### 7. (Opcional) Configurar systemd para produção

```bash
sudo nano /etc/systemd/system/mikrotik-watch.service
```

```ini
[Unit]
Description=Mikrotik Watch
After=network.target postgresql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/mikrotik-watch/src
ExecStart=/usr/bin/php -S 0.0.0.0:8080 -t /var/www/mikrotik-watch/src
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable mikrotik-watch
sudo systemctl start mikrotik-watch
```

## Atualização

```bash
sudo ./update.sh
```

O script irá:
1. Criar backup do `.env`
2. Executar `git pull`
3. Atualizar dependências do Composer
4. Aplicar migrations pendentes
5. Reiniciar o serviço

## Estrutura do Projeto

```
Mikrotik Watch/
├── install.sh              # Script de instalação automatizada
├── update.sh               # Script de atualização
├── .env.example            # Exemplo de configuração
├── .gitignore
├── composer.json
├── phpunit.xml
├── README.md
├── PROJETO.md              # Documentação técnica
├── database/
│   └── init.sql            # Schema inicial do banco
├── src/
│   ├── index.php           # Front controller
│   ├── cron/               # Scripts de coleta
│   ├── config/
│   │   ├── config.php      # Configurações + loader do .env
│   │   ├── database.php    # Conexão PDO
│   │   └── routes.php      # Definição de rotas
│   ├── src/
│   │   ├── Router.php      # Router simples
│   │   ├── Service/        # Serviços (MikrotikClient, CredentialCrypto, Crypto)
│   │   ├── Exception/      # Exceções customizadas
│   │   ├── Middleware/      # Middlewares
│   │   └── Controller/     # Controllers por domínio
│   ├── views/
│   │   ├── layouts/        # header.php, footer.php
│   │   ├── auth/
│   │   ├── dashboard/
│   │   ├── mikrotiks/
│   │   └── errors/
│   └── assets/
│       ├── css/style.css
│       └── js/app.js
└── tests/
    ├── bootstrap.php
    ├── setup_test_db.php
    ├── Unit/
    └── Integration/
```

## Credenciais Padrão

| Campo | Valor |
|-------|-------|
| Login | `admin` |
| Senha | `admin` |

> ⚠️ **IMPORTANTE**: Altere a senha padrão após o primeiro login!

## Comandos Úteis

| Comando | Descrição |
|---------|-----------|
| `systemctl status mikrotik-watch` | Ver status do serviço |
| `journalctl -u mikrotik-watch -f` | Ver logs em tempo real |
| `systemctl restart mikrotik-watch` | Reiniciar serviço |
| `php -S 0.0.0.0:8080 -t src/` | Servidor de desenvolvimento |
| `composer test` | Executar todos os testes |
| `composer test:unit` | Executar apenas testes unitários |
| `composer test:integration` | Executar apenas testes de integração |
| `./update.sh` | Atualizar projeto |

## Testes

### Configurar banco de testes

```bash
# Criar banco mikrotik_watch_test com schema completo
sudo ./database/setup_test.sh

# Ou manualmente
sudo ./tests/setup_test_db.php
```

### Executar testes

```bash
# Todos os testes
composer test

# Apenas unitários (sem banco de dados)
composer test:unit

# Apenas integração (requer banco mikrotik_watch_test)
composer test:integration

# Com cobertura de código
composer test:coverage
```

### Variáveis de ambiente para testes

| Variável | Padrão | Descrição |
|----------|--------|-----------|
| `TEST_DB_HOST` | `127.0.0.1` | Host do PostgreSQL |
| `TEST_DB_PORT` | `5432` | Porta do PostgreSQL |
| `TEST_DB_NAME` | `mikrotik_watch_test` | Nome do banco de testes |
| `TEST_DB_USER` | `mikrotik_watch` | Usuário do PostgreSQL |
| `TEST_DB_PASSWORD` | `` | Senha do PostgreSQL |

### Suites de teste

| Suite | Descrição | Requer DB |
|-------|-----------|----------|
| `Unit/RouterTest` | Roteador URL | Não |
| `Unit/CredentialCryptoTest` | Criptografia de senhas | Não |
| `Unit/MikrotikClientTest` | Cliente API REST | Não |
| `Unit/AuthMiddlewareTest` | Middleware de autenticação | Não |
| `Unit/MikrotikCrudTest` | Validação e criptografia Mikrotik | Não |
| `Unit/BatchRequestTest` | Requisições em paralelo (curl_multi) | Não |
| `Integration/ClientCrudTest` | CRUD de clientes | Sim |
| `Integration/MikrotikCrudIntegrationTest` | CRUD de Mikrotiks + criptografia | Sim |
| `Integration/NetwatchSyncTest` | Sincronização Netwatch | Sim |
| `Integration/AdminViewerTest` | Restrição de roles admin/viewer | Sim |
| `Integration/PingDeviceTest` | Dispositivos ping (ICMP) | Sim |
| `Integration/HealthAggregationTest` | Agregação e retenção de health_log | Sim |
| `Integration/PaginationTest` | Paginação de clientes, equipamentos, hosts | Sim |
| `Integration/HealthDataTest` | Dados de saúde e gráficos (health-data) | Sim |
| `Integration/TvDashboardTest` | Dashboard TV (acesso público, dados agregados) | Sim |

**Total: 156 testes** (65 unitários + 91 integração)

## Configuração de Cron

Para coleta automática de dados, adicione ao crontab:

```bash
# Editar crontab
crontab -e

# Adicionar linhas (coleta a cada 1 minuto)
* * * * * cd /var/www/Mikrotik\ Watch/src && php cron/collect.php >> /var/log/mikrotik-watch/cron.log 2>&1
* * * * * cd /var/www/Mikrotik\ Watch/src && php cron/collect_netwatch.php >> /var/log/mikrotik-watch/cron.log 2>&1
```

## Contribuindo

1. Faça um fork do repositório
2. Crie uma branch para sua feature (`git checkout -b feature/nova-feature`)
3. Faça commit das mudanças (`git commit -m 'Adiciona nova feature'`)
4. Push para a branch (`git push origin feature/nova-feature`)
5. Abra um Pull Request

## Estrutura de Commits

```
tipo(escopo): descrição curta

Exemplos:
feat(mikrotik): adiciona teste de conexão via API
fix(dashboard): corrige gráfico de tráfego não atualizando
docs(readme): adiciona instruções de instalação manual
refactor(router): simplifica resolução de rotas
```

## Licença

Este projeto está licenciado sob a licença MIT. Veja o arquivo [LICENSE](LICENSE) para detalhes.

## Contato

- Issues: [GitHub Issues](https://github.com/seu-usuario/mikrotik-watch/issues)
- Email: seu@email.com

---

Desenvolvido com ❤️ para a comunidade Mikrotik.
