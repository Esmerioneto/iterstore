# Iterstore

Plataforma de e-commerce em PHP com PostgreSQL. Inclui um painel administrativo e uma API GraphQL para frontends externos, como React, PWA e o app mobile.

## Requisitos

- PHP 8.1 ou superior, com `pdo_pgsql`
- Composer
- PostgreSQL 15 (há um `docker-compose.yml` pronto para ele, para desenvolvimento)
- Para o backup: `restic` e o `pg_dump`/`psql` da versão do PostgreSQL ou mais nova

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
| `src/vendor/Iter/` | Módulos do sistema: Core, Router, Admin, Config, Eav, Catalog, Inventory, Customer, Sales, Adminsales, Payment, Shipping, Mercadopago, Melhorenvio, Correios, Promotion, Message, Log, Report, Store, Mail |
| `src/app/code/` | Módulos locais |
| `src/etc/` | Configuração: banco, e-mail, chave e módulos ativos (`config.json`) |
| `public/` | Raiz web: `index.php`, assets e mídia enviada |
| `bin/` | Scripts de linha de comando |
| `var/` | Logs, e-mails gerados e backups |

Os módulos são ativados e desativados em Admin → Configurações. Depois de ativar um módulo pela primeira vez, rode `php bin/migrate.php up`.

O módulo Report tem os relatórios (Relatórios no menu), com exportação em CSV, Excel e PDF, envio automático por e-mail e as consultas `reports` e `report` na API do admin.

O módulo Log guarda o log de operações: quem fez o quê no admin (tela ou API), com o antes e o depois de cada campo alterado. A consulta fica em Sistema → Log de operações; os detalhes estão em `src/vendor/Iter/Log/README.md`.

## Scripts

| Comando | Para quê |
|---|---|
| `php bin/migrate.php [up\|down]` | Aplica ou reverte as migrações |
| `php bin/create-admin.php <usuario> <senha> <email>` | Cria um usuário administrador com acesso total |
| `php bin/schema-dump.php` | Regenera o `schema.graphql`. Rode depois de qualquer mudança na API |
| `php bin/db-bench.php [pageSize] [runs]` | Benchmark das listagens EAV |
| `php bin/backup.php [now [marcação] \| verify \| zip [cópia]]` | Backup agora, teste de restauração ou o `.zip` de uma cópia (o cron roda sem argumentos) |

## Cron

```cron
* * * * *  php /caminho/do/iterstore/bin/mail-worker.php   # envia a fila de e-mails
15 * * * * php /caminho/do/iterstore/bin/order-clean.php   # cancela pedidos não pagos
* * * * *  /caminho/do/iterstore/bin/mercadopago-sync-loop.sh   # confere pagamentos do Mercado Pago a cada 5 s
10 0 * * * php /caminho/do/iterstore/bin/log-clean.php     # apaga logs antigos (arquivos e log de operações)
20 0 * * * php /caminho/do/iterstore/bin/quote-clean.php   # apaga carrinhos abandonados
30 7 * * * php /caminho/do/iterstore/bin/report-mail.php   # relatórios por e-mail (se ligado)
* * * * *  php /caminho/do/iterstore/bin/backup.php >> /caminho/do/iterstore/var/log/backup.log 2>&1   # backup no intervalo de Configurações → Backup
0,15,30,45 * * * * php /caminho/do/iterstore/bin/marketing.php   # e-mails de marketing (regras de Configurações → Marketing)
```

Sem o `mail-worker`, os e-mails ficam parados na fila. Os prazos usados pelos outros scripts são definidos em Admin → Configurações.

## Testes

```bash
composer test
```

O comando roda `view-selftest`, `db-selftest`, `admin-selftest`, `promotion-selftest` (o cálculo das promoções), `report-selftest` (os relatórios, as exportações e a API de relatórios, numa transação desfeita), `backup-selftest` (um backup de verdade num repositório descartável, com o teste de restauração e o zip) e `shipping-provider-selftest` (Melhor Envio e Correios com um cliente falso, sem rede; os Correios só com o módulo ligado). O `admin-selftest` usa o banco real: sobe um `php -S`, cria registros `selftest_*` e apaga tudo ao terminar. Ele também falha se o `schema.graphql` estiver desatualizado.
