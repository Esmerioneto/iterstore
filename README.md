# Iterstore

Plataforma de e-commerce em PHP com PostgreSQL. Inclui um painel administrativo e uma API GraphQL para frontends externos, como React, PWA e o app mobile.

## Requisitos

- PHP 8.1 ou superior, com `pdo_pgsql`
- Composer
- PostgreSQL 15 (há um `docker-compose.yml` pronto para ele)

## Instalação

```bash
composer install
docker compose up -d                                  # sobe o PostgreSQL
cp src/etc/database.ini.sample src/etc/database.ini   # credenciais do banco
cp src/etc/mail.ini.sample src/etc/mail.ini           # SMTP
php bin/migrate.php up                                # cria as tabelas
php bin/create-admin.php <usuario> <senha> <email>    # primeiro usuário do admin
php -S localhost:8080 -t public                       # servidor de desenvolvimento
```

- Admin: http://localhost:8080/admin
- GraphQL: http://localhost:8080/core/graphql/index (contrato em `schema.graphql`)

O arquivo `src/etc/env.ini` guarda a chave que criptografa os segredos gravados no banco. Ele é criado no primeiro uso. **Não o perca**: sem ele, esses segredos não podem ser lidos.

## Estrutura

| Caminho | Conteúdo |
|---|---|
| `src/vendor/Iter/` | Módulos do sistema: Core, Router, Admin, Config, Eav, Catalog, Inventory, Customer, Sales, Adminsales, Payment, Shipping, Mercadopago, Store, Mail |
| `src/app/code/` | Módulos locais |
| `src/etc/` | Configuração: banco, e-mail, chave e módulos ativos (`config.json`) |
| `public/` | Raiz web: `index.php`, assets e mídia enviada |
| `bin/` | Scripts de linha de comando |
| `var/` | Logs, e-mails gerados e backups |

Os módulos são ativados e desativados em Admin → Configurações. Depois de ativar um módulo pela primeira vez, rode `php bin/migrate.php up`.

## Scripts

| Comando | Para quê |
|---|---|
| `php bin/migrate.php [up\|down]` | Aplica ou reverte as migrações |
| `php bin/create-admin.php <usuario> <senha> <email>` | Cria um usuário administrador com acesso total |
| `php bin/schema-dump.php` | Regenera o `schema.graphql`. Rode depois de qualquer mudança na API |
| `php bin/db-bench.php [pageSize] [runs]` | Benchmark das listagens EAV |

## Cron

```cron
* * * * *  php /caminho/do/iterstore/bin/mail-worker.php   # envia a fila de e-mails
15 * * * * php /caminho/do/iterstore/bin/order-clean.php   # cancela pedidos não pagos
0,10,20,30,40,50 * * * * php /caminho/do/iterstore/bin/mercadopago-sync.php   # confere pagamentos do Mercado Pago
10 0 * * * php /caminho/do/iterstore/bin/log-clean.php     # apaga logs antigos
20 0 * * * php /caminho/do/iterstore/bin/quote-clean.php   # apaga carrinhos abandonados
```

Sem o `mail-worker`, os e-mails ficam parados na fila. Os prazos usados pelos outros três scripts são definidos em Admin → Configurações.

## Testes

```bash
composer test
```

O comando roda `view-selftest`, `db-selftest` e `admin-selftest`. O `admin-selftest` usa o banco real: sobe um `php -S`, cria registros `selftest_*` e apaga tudo ao terminar. Ele também falha se o `schema.graphql` estiver desatualizado.
