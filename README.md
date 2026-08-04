# AdminPrime

Desenvolvimento de Ferramentas Inteligentes — painel interno da PrimeERP para gestão de clientes: KPIs, demandas, documentações e treinamentos.

## Stack

- PHP puro (sem framework), PDO + MySQL/MariaDB.
- Bootstrap 5 (via CDN) para a interface.
- Sem dependências via Composer/NPM — funciona em qualquer hospedagem com PHP 7.4+ e extensão PDO MySQL.

## Estrutura do projeto

```
AdminPrime/
├── index.php           # redireciona para login ou dashboard
├── login.php / logout.php
├── dashboard.php        # visão geral e clientes que precisam de atenção
├── clientes.php         # lista de clientes
├── cliente.php          # detalhe do cliente (abas: visão geral, demandas, KPIs, documentos, treinamentos)
├── usuarios.php         # gestão de usuários (somente admin)
├── download.php         # download seguro de documentos
├── bootstrap.php        # inicialização (config, autoload, conexão, migrations)
├── config/
│   └── config.php       # configurações e credenciais do banco
├── src/                 # classes PHP (Database, Auth, Migrations)
├── templates/           # header/footer compartilhados
├── database/
│   └── schema.sql        # schema de referência (a app cria as tabelas sozinha)
├── uploads/clientes/     # arquivos enviados por cliente (não acessível direto via URL)
└── assets/               # CSS
```

## Como funciona o banco de dados

Não é necessário importar SQL manualmente: na primeira requisição, `src/Migrations.php`
cria todas as tabelas automaticamente (`CREATE TABLE IF NOT EXISTS`) e insere:

- um usuário administrador inicial,
- os clientes iniciais (WERK, SCHOTT, SGT, PREVER, DS TECNOLOGIA, GEOMAQ, ICOMON, LQF, VITAPET, 3LIFE).

**Login inicial:**
- E-mail: `kleber.farias@primeerp.com.br`
- Senha: `AdminPrime@2026`

**Troque essa senha assim que acessar** (Usuários → criar seu usuário definitivo, ou avise que eu implemento uma tela de troca de senha).

## Deploy

O deploy é feito via integração Git nativa do Hostinger (hPanel → Avançado → Git),
conectada ao repositório `PrimeERPSolucoes/AdminPrime`, branch `main`, diretório
`public_html/AdminPrime`. Qualquer push nesse branch é publicado automaticamente
(ou manualmente com o botão "Reimplantar" no hPanel).

## Segurança

- Senhas com hash (`password_hash`/`password_verify`).
- Proteção CSRF em todos os formulários.
- Pastas internas (`src/`, `config/`, `templates/`, `database/`, `uploads/`) bloqueadas
  para acesso direto via `.htaccess`.
- Downloads de documentos passam por `download.php`, que verifica login e impede
  acesso a arquivos fora da pasta de uploads.

## Roadmap (ainda não implementado)

- Portal por cliente para upload de arquivos PRW/PTM (ADVPL/Protheus), com fonte e
  aplicação de patches.
- Tela de troca de senha pelo próprio usuário.
- Notificações/alertas de prazos.
