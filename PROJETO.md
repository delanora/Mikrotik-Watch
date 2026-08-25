# Mikrotik Watch - Documentação Técnica

## Visão Geral

O **Mikrotik Watch** é um painel de monitoramento web para gestão de múltiplos equipamentos Mikrotik RouterOS. O sistema coleta métricas de tráfego, status de interfaces e informações de sistema em intervalos configuráveis via cron.

## Stack

| Camada | Tecnologia |
|--------|------------|
| Backend | PHP 8.4 puro (sem framework) |
| Frontend | HTML, CSS, JavaScript puro |
| Banco de Dados | PostgreSQL 17 |
| Gráficos | Chart.js via CDN |
| Autoload | PSR-4 (Composer) |
| Testes | PHPUnit 11 |

## Arquitetura

### Padrão MVC Simples

O projeto segue um padrão arquitetural simplificado sem framework:

```
HTTP Request → index.php (Front Controller) → Router → Controller → Service → Database
                                                                  ↓
                                                         View (PHP template)
```

### Camadas

1. **Front Controller** (`src/index.php`): Ponto de entrada único. Recebe todas as requisições HTTP e despacha para o Router.

2. **Router** (`src/src/Router.php`): Mapeia URIs para controllers/ações. Suporta parâmetros dinâmicos (`{id}`).

3. **Controllers** (`src/src/Controller/`): Lógica de controle. Um controller por domínio (Dashboard, Auth, Mikrotik, etc.).

4. **Services** (`src/src/Service/`): Lógica de negócio e integração externa. O `MikrotikClient` encapsula a comunicação com a API do RouterOS.

5. **Middleware** (`src/src/Middleware/`): Cross-cutting concerns (autenticação, rate limiting, etc.).

6. **Views** (`src/views/`): Templates PHP para HTML. Layouts em `views/layouts/`, uma pasta por domínio.

7. **Config** (`src/config/`): Configuração centralizada. Loader do `.env`, conexão PDO, e definição de rotas.

### Coleta de Dados (Cron)

Scripts em `src/cron/` são executados via crontab para coletar dados dos equipamentos Mikrotik:

- Conectam via API TCP (porta 8728 padrão)
- Coletam: interfaces, tráfego, system resource
- Armazenam no PostgreSQL (tabela `traffic_metrics`)
- Métricas antigos podem ser agregadas/limpas periodicamente

### Fluxo de Autenticação

1. Usuário acessa `/login`
2. POST com credenciais
3. Password verificado via `password_verify()` (bcrypt)
4. Sessão criada em `active_sessions`
5. Cookie de sessão definido
6. AuthMiddleware verifica sessão em rotas protegidas

## Estrutura do Banco de Dados

### Tabelas Principais

| Tabela | Descrição |
|--------|-----------|
| `users` | Usuários do sistema (admin, viewer) |
| `mikrotiks` | Equipamentos cadastrados |
| `interfaces` | Interfaces de cada equipamento |
| `traffic_metrics` | Métricas de tráfego (time series) |
| `active_sessions` | Sessões ativas de login |
| `activity_log` | Log de ações do sistema |

### Índices Importantes

- `traffic_metrics(collected_at)`: Para queries temporais
- `traffic_metrics(interface_id)`: Para tráfego por interface
- `active_sessions(session_token)`: Para lookup rápido de sessão

## Endpoints

### Páginas (GET)

| Rota | Descrição |
|------|-----------|
| `GET /` | Redireciona para dashboard |
| `GET /login` | Formulário de login |
| `GET /logout` | Encerra sessão |
| `GET /dashboard` | Painel principal |
| `GET /mikrotiks` | Lista de equipamentos |
| `GET /mikrotiks/{id}` | Detalhes de um equipamento |
| `GET /mikrotiks/{id}/edit` | Formulário de edição |
| `GET /settings` | Configurações |

### API (JSON)

| Rota | Descrição |
|------|-----------|
| `GET /api/stats` | Estatísticas gerais |
| `GET /api/mikrotiks` | Lista de equipamentos |
| `GET /api/traffic/{id}` | Dados de tráfego |

## Convenções de Código

- **PHP**: `declare(strict_types=1)` em todos os arquivos
- **Namespaces**: `App\` para código, `App\Tests\` para testes
- **Controllers**: Um arquivo por classe, um método por ação
- **Services**: Stateless quando possível, métodos estáticos para utilitários
- **Variáveis**: camelCase para variáveis, PascalCase para classes
- **Arquivos SQL**: Numerados para controle de ordem de execução

## Segurança

- Senhas de API Mikrotik criptografadas no banco
- Senhas de usuário com bcrypt (cost 12)
- Arquivo `.env` com permissão 600
- Prepared statements (PDO) contra SQL injection
- CSRF tokens em formulários (TODO)
- Rate limiting em endpoints de login (TODO)

## Ambientes

| Variável | Descrição | Valores |
|----------|-----------|---------|
| `APP_ENV` | Ambiente atual | `production`, `development`, `testing` |
| `APP_DEBUG` | Modo debug | `true`, `false` |

## Cron Jobs

```bash
# Coleta de dados a cada 5 minutos
*/5 * * * * cd /var/www/Mikrotik Watch/src && php cron/collect.php >> /var/log/mikrotik-watch/cron.log 2>&1
```

## Dependências PHP

| Extensão | Uso |
|----------|-----|
| `pdo_pgsql` | Conexão PostgreSQL |
| `curl` | Comunicação com API Mikrotik |
| `mbstring` | Manipulação de strings UTF-8 |
| `openssl` | Criptografia de dados sensíveis |
| `json` | Serialização JSON para API |
