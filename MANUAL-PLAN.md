# Plano do Manual do Sistema

Anotações para redigir o manual depois que o desenvolvimento terminar.
Cada funcionalidade entregue ganha uma seção aqui: o que ela faz, onde fica
no admin e o que o usuário ou o administrador precisa saber.

## Configurações (Ajustes do Sistema)

- **Onde:** Admin → Configurações (`/admin/config/index`). Exige o recurso ACL `Iter_Config::config`.
- **O que faz:** formulário "Ajustes do Sistema", montado automaticamente e agrupado por seção. A lista "Módulos do Sistema" aparece logo abaixo.
- **Ajustes disponíveis:**
  - Segurança do admin → *Tentativas de login antes do bloqueio*: padrão 5, aceita de 1 a 100.
  - Segurança do admin → *Minutos de bloqueio após exceder as tentativas*: padrão 15, aceita de 1 a 1440.
  - Segurança do admin → *Tamanho mínimo da senha*: padrão 8, aceita de 1 a 64.
  - Segurança do admin → *Exigir letras e números na senha*: padrão Sim. É um campo Sim/Não.
  - Segurança do admin → *Senhas anteriores que não podem ser reutilizadas*: padrão 4, aceita de 0 a 20. Com 0, não há restrição.
  - Segurança do admin → *Dias até a senha expirar*: padrão 90, aceita de 0 a 3650. Com 0, a senha nunca expira.
- **Comportamento:**
  - Enquanto nada for salvo, vale o valor padrão.
  - Um valor inválido mostra uma mensagem de erro e mantém o que foi digitado no formulário.
  - Os valores valem para o sistema inteiro. Ainda não há ajuste diferente por website ou loja.
- **Bloqueio de login:** depois de N senhas erradas seguidas, o usuário fica bloqueado por X minutos, e nesse período nem a senha correta é aceita. Ao fim do bloqueio, a contagem de tentativas recomeça do zero.
- **Seção para desenvolvedores:**
  - Para criar um ajuste, adicione uma entrada em `etc/config_fields.json` do módulo, com os campos `path`, `group`, `label`, `type` (`number`, `text` ou `boolean`), `default`, `min` e `max`.
  - Para ler o valor no código, use `\Iter\Core\Config::get('path')`.
  - Os valores ficam gravados na tabela `core_config_data`.

## Senhas (histórico e expiração)

- **Alterar senha:** link "Alterar senha" no cabeçalho (`/admin/auth/password`). O usuário informa a senha atual, a nova senha e a confirmação.
- **Formato:** a senha precisa ter pelo menos 8 caracteres e conter letras e números. Letras acentuadas contam como letras. As duas regras podem ser ajustadas em Configurações.
  - A regra vale no formulário de Usuários, na tela "Alterar senha" e no `bin/create-admin.php`.
  - Senhas criadas antes da regra continuam valendo até a próxima troca.
- **Reuso:** a nova senha não pode ser igual a nenhuma das últimas N senhas, onde N vem de Configurações. A regra vale tanto na tela "Alterar senha" quanto quando um administrador muda a senha de alguém em Usuários.
- **Expiração:**
  - Passados X dias desde a última troca, qualquer página do admin leva para "Alterar senha", com o aviso "Sua senha expirou". O acesso volta ao normal depois da troca.
  - Uma alteração do prazo em Configurações vale na hora para todos os usuários, porque o prazo é calculado a partir da data da última troca.
  - Os usuários que já existiam antes deste recurso começaram a contar o prazo em 23/09/2026.
- **Recuperação:** `php bin/create-admin.php` redefine a senha aplicando a regra de formato, mas não a de reuso. É o caminho quando um administrador fica sem acesso.
- **Seção para desenvolvedores:**
  - O histórico fica na tabela `admin_passwords` e é gravado automaticamente sempre que a senha muda, qualquer que seja o caminho.
  - As regras ficam em `AdminUser::validatePasswordStrength()`, `AdminUser::isPasswordReused()` e `AdminUser::isPasswordExpired()`.

## Fuso horário

- O sistema usa o horário de Brasília (`America/Sao_Paulo`). O fuso é definido em `functions/bootstrap.php`, e a conexão com o banco segue o mesmo fuso automaticamente.
- As datas são gravadas sem fuso ("timestamp without time zone"). Mudar o fuso depois exige converter os dados já gravados, como faz a migration `Iter_Core/20260923000003`.

## Dashboard

- **Onde:** Admin → Dashboard (`/admin/index`). Exige o recurso ACL `Iter_Admin::dashboard`.
- **Saudação:** mostra o primeiro nome do usuário logado ou, se não houver, o nome de usuário.
- **Card Usuários:** aparece só para quem tem o recurso `Iter_Admin::user`. Mostra:
  - quantos usuários estão ativos, inativos e bloqueados neste momento;
  - a lista dos bloqueados, com o horário em que cada um é liberado;
  - os 5 últimos logins, com o total de logins de cada usuário.
- **Card Lojas:** aparece só para quem tem o recurso `Iter_Store::store`. Mostra:
  - quantos websites, grupos e lojas existem, com link para cada tela;
  - um aviso quando há lojas sem grupo de loja.
- **Seção para desenvolvedores:**
  - Um módulo adiciona seu card criando `view/frontend/layout/admin_dashboard.json` com um filho em `dashboard.children`, porque os layouts de todos os módulos são mesclados.
  - O próprio componente confere a permissão com `Auth::isAllowed` e não mostra nada quando o usuário não pode ver o card.

## Funcionalidades já entregues (a detalhar)

- **Login e logout do admin** (`/admin/auth/login`).
  - Ao entrar, o usuário é levado para a primeira página do menu que o perfil dele pode acessar.
  - Por segurança, os formulários do admin recusam envios sem um token válido (CSRF). Quando isso acontece, aparece a mensagem de sessão expirada.
- **Usuários e Perfis:**
  - Cada perfil define quais recursos do sistema (ACL) ele pode acessar.
  - O menu mostra só o que o perfil permite. Uma página sem permissão exibe o erro 403.
  - O primeiro administrador é criado pela linha de comando: `php bin/create-admin.php <usuario> <senha> <email>`.
- **Websites, Grupos de loja e Lojas** (menu Configurações):
  - A hierarquia é Website → Grupo → Loja.
  - Só pode haver um website padrão.
  - Um website ou grupo que ainda tem itens ligados a ele não pode ser removido.
  - Na loja, o grupo é opcional.
- **API GraphQL de lojas:**
  - A leitura é pública.
  - Criar, alterar e remover exigem uma sessão do admin com o recurso `Iter_Store::store`.
- **Operação:**
  - Instalação: clonar o repositório, rodar `composer install`, copiar `src/etc/database.ini.sample` para `src/etc/database.ini` e ajustar as credenciais, subir o banco com `docker compose up -d` e rodar `php bin/migrate.php up`.
  - `php bin/migrate.php up|down` aplica ou desfaz as migrations.
  - `php bin/view-selftest.php` testa as views.
