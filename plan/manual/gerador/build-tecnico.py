#!/usr/bin/env python3
"""Builds plan/manual/MANUAL-TECNICO.html from the real API answers (api-examples.json) and schema.graphql."""
import html, json, re, sys

ROOT = '/home/esmerio/projetos/iterstore'
EX = json.load(open(sys.argv[1]))
OUT = ROOT + '/plan/manual/MANUAL-TECNICO.html'
e = html.escape


def short_tokens(v):
    if isinstance(v, str) and re.fullmatch(r'[0-9a-f]{64}', v):
        return v[:8] + '…' + v[-4:]
    if isinstance(v, str) and re.fullmatch(r'\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d+', v):
        return v[:19]
    return v


def trim(v, keep=3):
    if isinstance(v, list):
        items = [trim(x, keep) for x in v[:keep]]
        if len(v) > keep:
            items.append(f'… mais {len(v) - keep}')
        return items
    if isinstance(v, dict):
        return {k: trim(x, keep) for k, x in v.items() if k != 'locations'}
    return short_tokens(v)


def dump(v):
    return json.dumps(v, ensure_ascii=False, indent=2)


def example(key, title, text='', keep=3, after=''):
    x = EX[key]
    req = ''
    x['headers'] = x['headers'] or {}
    if x['token'] or x['headers']:
        hs = []
        if x['token']:
            hs.append('Authorization: Bearer <token>')
        hs += [f'{h}: {v}' for h, v in x['headers'].items()]
        req += '<div class="hdr">' + '<br>'.join(e(h) for h in hs) + '</div>'
    query = x['query'].strip()
    req += f'<pre class="gql">{e(query)}</pre>'
    if x['variables'] and isinstance(x['variables'], dict):
        req += f'<div class="lbl">Variáveis</div><pre class="json">{e(dump(trim(x["variables"])))}</pre>'
    res = x['response']
    res = trim(res, keep)
    return f'''
  <div class="ex">
    <h4>{e(title)}</h4>
    {f"<p>{text}</p>" if text else ""}
    <div class="lbl">Requisição</div>
    {req}
    <div class="lbl">Resposta</div>
    <pre class="json">{e(dump(res))}</pre>
    {after}
  </div>'''


# --- reference table from schema.graphql ---------------------------------------------------------------------
schema = open(ROOT + '/schema.graphql').read()


def fields(type_name):
    block = re.search(r'^type ' + type_name + r' \{\n(.*?)^\}', schema, re.S | re.M).group(1)
    out, desc, depth = [], '', 0
    lines = block.split('\n')
    i = 0
    while i < len(lines):
        line = lines[i]
        if depth == 0:
            if line.startswith('  """'):
                j = i + 1
                buf = []
                while not lines[j].strip().startswith('"""'):
                    buf.append(lines[j].strip())
                    j += 1
                desc = ' '.join(buf)
                i = j + 1
                continue
            m = re.match(r'^  "(.*)"$', line)
            if m:
                desc = m.group(1).replace('\\"', '"')
                i += 1
                continue
            m = re.match(r'^  (\w+)', line)
            if m:
                out.append((m.group(1), desc))
                desc = ''
        depth += line.count('(') - line.count(')')
        i += 1
    return out


PUBLIC = 'público'
CUST = 'cliente (token)'
ACCESS = {
    'store': PUBLIC, 'stores': PUBLIC, 'websites': PUBLIC, 'storeInfo': PUBLIC, 'product': PUBLIC, 'categories': PUBLIC,
    'category': PUBLIC, 'route': PUBLIC, 'products': PUBLIC, 'mobileHome': PUBLIC, 'mobileCategoryProducts': PUBLIC,
    'mobileOrder': 'admin: Iter_Catalog::mobile', 'inventorySources': 'admin: Iter_Inventory::source', 'inventorySource': 'admin: Iter_Inventory::source',
    'customer': 'admin: Iter_Customer::customer', 'me': CUST, 'customers': 'admin: Iter_Customer::customer',
    'attributes': PUBLIC + ' (os ocultos só para o admin)', 'cart': PUBLIC + ' (cartId) ou cliente', 'orders': 'admin: Iter_Sales::order',
    'order': 'admin: Iter_Sales::order', 'myOrders': CUST, 'myOrder': CUST, 'guestOrder': PUBLIC + ' (número + e-mail)',
    'salesSummary': 'admin: Iter_Sales::order', 'shippingTableRates': 'admin: Iter_Shipping::rates', 'shippingQuotes': PUBLIC,
    'mercadopagoSettings': PUBLIC, 'customerMessages': CUST, 'customerUnreadMessages': CUST,
    'adminMessageInbox': 'admin: Iter_Message::manage', 'adminCustomerMessages': 'admin: Iter_Message::manage',
    'reports': 'admin: Iter_Report::*', 'report': 'admin: a permissão do relatório', 'currentAdmin': 'admin (qualquer)',
    'createStore': 'admin: Iter_Store::store', 'updateStore': 'admin: Iter_Store::store', 'deleteStore': 'admin: Iter_Store::store',
    'saveMobileOrder': 'admin: Iter_Catalog::mobile', 'createProduct': 'admin: Iter_Catalog::product', 'updateProduct': 'admin: Iter_Catalog::product',
    'saveVariants': 'admin: Iter_Catalog::product', 'deleteProduct': 'admin: Iter_Catalog::product', 'uploadProductImage': 'admin: Iter_Catalog::product',
    'createCategory': 'admin: Iter_Catalog::category', 'updateCategory': 'admin: Iter_Catalog::category', 'moveCategory': 'admin: Iter_Catalog::category',
    'setCategoryBanners': 'admin: Iter_Catalog::category', 'deleteCategory': 'admin: Iter_Catalog::category', 'setProductStock': 'admin: Iter_Inventory::stock',
    'registerCustomer': PUBLIC, 'confirmCustomerEmail': PUBLIC, 'generateCustomerToken': PUBLIC, 'refreshCustomerToken': CUST,
    'revokeCustomerToken': CUST, 'requestCustomerPasswordReset': PUBLIC, 'resetCustomerPassword': PUBLIC, 'updateMe': CUST,
    'changeMyPassword': CUST, 'createMyAddress': CUST, 'updateMyAddress': CUST, 'setMyDefaultAddress': CUST, 'deleteMyAddress': CUST,
    'createCustomer': 'admin: Iter_Customer::customer', 'updateCustomer': 'admin: Iter_Customer::customer', 'deleteCustomer': 'admin: Iter_Customer::customer',
    'createCart': PUBLIC, 'addToCart': PUBLIC + ' ou cliente', 'updateCartItem': PUBLIC + ' ou cliente', 'removeCartItem': PUBLIC + ' ou cliente',
    'mergeCarts': CUST, 'setCartGuestInfo': PUBLIC, 'setCartShippingAddress': PUBLIC + ' ou cliente', 'setCartShippingMethod': PUBLIC + ' ou cliente',
    'setCartPaymentMethod': PUBLIC + ' ou cliente', 'applyCartCoupon': PUBLIC + ' ou cliente', 'removeCartCoupon': PUBLIC + ' ou cliente',
    'placeOrder': PUBLIC + ' ou cliente', 'setCartBillingAddress': PUBLIC + ' ou cliente', 'changeMyOrderPaymentMethod': CUST,
    'confirmOrderPayment': 'admin: Iter_Sales::order_payment', 'shipOrder': 'admin: Iter_Sales::order_ship', 'completeOrder': 'admin: Iter_Sales::order_ship',
    'cancelOrder': 'admin: Iter_Sales::order_cancel', 'addOrderComment': 'admin: Iter_Sales::order', 'createAdminCart': 'admin: Iter_Adminsales::create',
    'placeAdminOrder': 'admin: Iter_Adminsales::create', 'saveShippingTableRates': 'admin: Iter_Shipping::rates',
    'payOrder': 'cliente, ou visitante com número + e-mail', 'sendCustomerMessage': CUST, 'adminSendCustomerMessage': 'admin: Iter_Message::manage',
    'generateAdminToken': PUBLIC, 'revokeAdminToken': 'admin (qualquer)',
}


def first_sentence(text):
    text = re.sub(r'\s+', ' ', text).strip()
    m = re.match(r'(.+?[.:])(\s|$)', text)
    return m.group(1).rstrip(':') + ('.' if m and m.group(1).endswith(':') else '') if m else text


FALLBACK = {
    'store': 'Uma loja (store view) pelo id.', 'stores': 'Todas as lojas (store views).',
    'products': 'Lista de produtos com busca, filtros por atributo, categoria e preço, página e as agregações para os filtros.',
    'inventorySources': 'As origens de estoque.', 'inventorySource': 'Uma origem de estoque pelo código.',
    'customers': 'Lista de clientes com busca, tipo (PF/PJ), grupo, website, situação e página.',
    'cart': 'O carrinho: do visitante pelo cartId, do cliente logado sem cartId; itens, totais, entrega, pagamento e formas disponíveis.',
    'orders': 'Lista de pedidos com filtros, ordenação, página e a soma (amount).',
    'shippingTableRates': 'As faixas de frete do website.', 'mercadopagoSettings': 'A Public Key e o máximo de parcelas, para o SDK do Mercado Pago no app.',
    'createStore': 'Cria uma loja (store view).', 'updateStore': 'Altera uma loja.', 'deleteStore': 'Exclui uma loja.',
    'createProduct': 'Cria um produto simples ou com grade, com os atributos, websites e categorias.', 'deleteProduct': 'Exclui o produto (e as variações).',
    'createCustomer': 'Cria um cliente pelo admin, com endereços.', 'removeCartItem': 'Tira um item do carrinho.',
}


def ref_table(type_name):
    rows = []
    for name, desc in fields(type_name):
        if name not in ACCESS:
            sys.exit(f'sem acesso definido: {name}')
        rows.append(f'<tr><td><code>{name}</code></td><td>{e(ACCESS[name])}</td><td>{e(first_sentence(desc)) if desc else e(FALLBACK[name])}</td></tr>')
    return '<table class="ref"><tr><th>Campo</th><th>Quem pode</th><th>Descrição (do schema)</th></tr>' + ''.join(rows) + '</table>'


q_fields, m_fields = fields('Query'), fields('Mutation')
missing = [n for n, _ in q_fields + m_fields if not _]

# --- error codes from the code ---------------------------------------------------------------------------------
ERRORS = [
    ('Comuns', [('UNAUTHENTICATED', 'Sem login (ou com o token de outro tipo: um token de cliente num campo do admin).'), ('FORBIDDEN', 'Logado, mas o perfil não tem a permissão.'),
                ('TOKEN_INVALID', 'HTTP 401: o token enviado não existe, expirou ou foi revogado. Faça login de novo.'),
                ('API_KEY_INVALID', 'HTTP 401: a chave do aplicativo é exigida e não veio, não existe ou foi revogada (cabeçalho X-Api-Key).'),
                ('TOO_MANY_REQUESTS', 'Requisições, tentativas de login ou cotações de frete demais do mesmo IP no minuto (HTTP 429 no limite geral), ou mensagens demais do mesmo cliente.'),
                ('PAYLOAD_TOO_LARGE', 'HTTP 413: o corpo da chamada passou do tamanho máximo (64 KB sem login de admin).'),
                ('UNSUPPORTED_MEDIA_TYPE', 'HTTP 415: chamada feita com a sessão do painel (cookie) sem Content-Type: application/json. Protege o admin de um formulário de outro site.'),
                ('GRAPHQL_PARSE_FAILED', 'A consulta tem erro de sintaxe.'), ('GRAPHQL_VALIDATION_FAILED', 'Campo ou argumento que não existe, variável de tipo errado, ou introspecção desligada. Sem login de admin, a mensagem não traz as sugestões de nomes ("Did you mean...").'),
                ('QUERY_LIMIT_EXCEEDED', 'A consulta passou da profundidade ou da complexidade máxima.'), ('NOT_FOUND', 'O registro não existe ou não é visível para quem pediu.'),
                ('INTERNAL_ERROR', 'Falha inesperada no servidor; a causa vai para o log do sistema.'), ('BAD_REQUEST', 'Pedido inválido, sem código mais específico.')]),
    ('Login e cadastro', [('INVALID_CREDENTIALS', 'Usuário ou senha errados, ou conta bloqueada temporariamente.'), ('PASSWORD_EXPIRED', 'A senha do admin venceu: troque pelo painel.'),
                          ('EMAIL_NOT_CONFIRMED', 'O cliente ainda não confirmou o e-mail.'), ('EMAIL_DUPLICATE', 'E-mail já cadastrado no website.'), ('TAXVAT_INVALID', 'CPF/CNPJ inválido.'),
                          ('TAXVAT_DUPLICATE', 'CPF/CNPJ já cadastrado no website.'), ('PASSWORD_INVALID', 'Senha curta demais ou senha atual errada.')]),
    ('Catálogo e estoque', [('PRODUCT_NOT_FOUND', 'SKU ou id que não existe.'), ('SKU_DUPLICATE', 'SKU já usado.'), ('CATEGORY_REQUIRED', 'O produto precisa de uma categoria do website.'),
                            ('URL_KEY_DUPLICATE', 'Chave de URL já usada.'), ('ATTRIBUTE_NOT_FOUND', 'Atributo fora do conjunto do produto.'), ('VALUE_INVALID', 'Valor inválido para o atributo.'),
                            ('VALUE_REQUIRED', 'Atributo obrigatório vazio.'), ('SOURCE_NOT_FOUND', 'Origem de estoque que não existe.')]),
    ('Carrinho e pedido', [('CART_NOT_FOUND', 'cartId que não existe (ou de outro cliente).'), ('PRODUCT_IS_CONFIGURABLE', 'Produto com grade: envie o SKU da variação.'),
                           ('INSUFFICIENT_STOCK', 'Quantidade acima do vendável.'), ('QUANTITY_INVALID', 'Quantidade zero, negativa ou fracionada onde não pode.'),
                           ('SHIPPING_CEP_INVALID', 'CEP inválido.'), ('SHIPPING_METHOD_UNAVAILABLE', 'Forma de entrega que não vale para o endereço/carrinho.'),
                           ('PAYMENT_METHOD_UNAVAILABLE', 'Forma de pagamento desligada ou fora do limite de valor.'), ('COUPON_NOT_FOUND', 'Cupom que não existe.'),
                           ('COUPON_EXPIRED', 'Cupom fora da validade.'), ('COUPON_USED_UP', 'Cupom sem usos restantes.'), ('COUPON_LOGIN_REQUIRED', 'Cupom só para cliente logado.'),
                           ('TOTAL_CHANGED', 'O total mudou entre a revisão e o placeOrder (expectedTotal): mostre o novo total e peça a confirmação.'),
                           ('ORDER_NOT_FOUND', 'Pedido que não existe (ou e-mail que não confere no guestOrder).'), ('ORDER_STATUS_INVALID', 'A ação não vale para a situação atual do pedido.'),
                           ('REASON_REQUIRED', 'Cancelamento com motivo OTHER sem comentário.'), ('REFUND_NOTE_REQUIRED', 'Cancelar pedido pago sem estorno nem nota de devolução.'),
                           ('PAYMENT_FAILED', 'O Mercado Pago recusou a cobrança (veja statusDetail).'), ('PAYMENT_APPROVED', 'A cobrança acabou de ser paga; o pedido foi confirmado.')]),
]


def errors_html():
    out = ''
    for group, items in ERRORS:
        out += f'<h4>{group}</h4><table><tr><th style="width:34%">Código</th><th>Quando</th></tr>' + ''.join(f'<tr><td><code>{c}</code></td><td>{e(t)}</td></tr>' for c, t in items) + '</table>'
    return out


style = re.search(r'<style>(.*?)</style>', open(ROOT + '/plan/manual/MANUAL-ITERSTORE.html').read(), re.S).group(1)
style += '''
  code { font-family: "JetBrains Mono", "DejaVu Sans Mono", Consolas, monospace; font-size: 9pt; background: var(--paper); border: 1px solid var(--line); border-radius: 4px; padding: 0 4px; }
  pre { font-family: "JetBrains Mono", "DejaVu Sans Mono", Consolas, monospace; font-size: 8.4pt; line-height: 1.45; background: #f6f5f1; border: 1px solid var(--line); border-radius: 6px; padding: 9px 12px; margin: 4px 0 8px; white-space: pre-wrap; word-break: break-word; }
  pre.gql { border-left: 3px solid var(--accent); }
  pre.json { border-left: 3px solid var(--green); }
  pre.sh { border-left: 3px solid var(--ink); }
  .ex { margin: 14px 0 20px; }
  .ex h4 { margin-top: 18px; }
  .lbl { font-size: 8pt; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); margin-top: 6px; }
  .hdr { font-family: "DejaVu Sans Mono", Consolas, monospace; font-size: 8.4pt; color: var(--accent); margin: 2px 0; }
  table.ref { font-size: 8.8pt; break-inside: auto; }
  table.ref td:first-child { white-space: nowrap; }
  table.ref td:nth-child(2) { width: 25%; }
  table { break-inside: auto; }
  tr { break-inside: avoid; }
'''

MODULES = [
    ('Core', '—', 'Banco (Adapter, Select), configuração, criptografia dos segredos, log, sessão, limite por IP, migrações e o endpoint GraphQL.'),
    ('Router', '—', 'Rotas: <code>/admin/&lt;controller&gt;/&lt;ação&gt;</code> e as rotas dos módulos (<code>etc/router.json</code>).'),
    ('Admin', '—', 'Login do painel, perfis e permissões (ACL), menu, token da API do admin.'),
    ('Config', '—', 'Configurações por escopo (padrão / website) e a tela de módulos.'),
    ('Mail', '—', 'Fila de e-mails, modelos editáveis e e-mails enviados.'),
    ('Store', '—', 'Websites, grupos e lojas.'),
    ('Eav', '—', 'Atributos, conjuntos e valores (produto, categoria, cliente, endereço).'),
    ('Catalog', 'Eav', 'Produtos (simples e com grade), categorias, imagens, custo, ordem do app e importação de custos.'),
    ('Inventory', 'Catalog', 'Origens de estoque, quantidade por origem, reservas e o vendável.'),
    ('Customer', 'Eav, Store', 'Clientes PF/PJ, endereços, login do cliente na loja e token da API.'),
    ('Sales', 'Catalog, Inventory, Customer, Store', 'Carrinho, pedido, situações, envio, cancelamento e painel de vendas.'),
    ('Adminsales', 'Sales', 'Vendas → Novo pedido (pedido feito pelo atendimento) e a API correspondente.'),
    ('Payment', 'Sales', 'Formas de pagamento manuais (Pix com chave, pagamento na entrega).'),
    ('Mercadopago', 'Payment', 'Pix, crédito e débito pelo Mercado Pago, webhook, sincronização e estorno.'),
    ('Shipping', 'Sales', 'Frete por faixas, retirada na loja e o registro de provedores.'),
    ('Melhorenvio', 'Shipping', 'Cotação pelo Melhor Envio (OAuth).'),
    ('Correios', 'Shipping', 'Cotação pela API dos Correios (contrato). <em>Desligado nesta instalação.</em>'),
    ('Promotion', 'Sales, Catalog, Store', 'Promoções automáticas e cupons.'),
    ('Message', 'Customer, Store', 'Mensagens entre a loja e o cliente.'),
    ('Log', 'Admin, Config', 'Log de operações (auditoria) com antes e depois.'),
    ('Report', 'Admin, Config, Sales', 'Os 26 relatórios, exportação, envio por e-mail e a API de relatórios.'),
]

CRON = [
    ('* * * * *', 'bin/mail-worker.php', 'Envia a fila de e-mails. Sem ele, nenhum e-mail sai.'),
    ('* * * * *', 'bin/mercadopago-sync-loop.sh', 'Roda o mercadopago-sync.php a cada 5 segundos dentro do minuto (com flock), para o Pix ser confirmado rápido. Alternativa mais leve: mercadopago-sync.php a cada 10 minutos.'),
    ('15 * * * *', 'bin/order-clean.php', 'Cancela os pedidos não pagos depois do prazo de cada forma de pagamento.'),
    ('10 0 * * *', 'bin/log-clean.php', 'Apaga os arquivos de log e o log de operações mais antigos que o prazo configurado.'),
    ('20 0 * * *', 'bin/quote-clean.php', 'Apaga carrinhos de visitante e pedidos em montagem do admin parados.'),
    ('30 7 * * *', 'bin/report-mail.php', 'Envio automático dos relatórios (segunda-feira ou dia 1º, se ligado).'),
    ('* * * * *', 'bin/backup.php', 'Backup com restic no intervalo de Configurações → Backup (ou ao "Fazer backup agora"); limpeza diária das cópias velhas e teste de restauração mensal. Ver 3.5.'),
]

doc = f'''<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Manual Técnico e da API — Iter Store</title>
<style>{style}</style>
</head>
<body>
<main>

<section class="cover">
  <div>
    <img class="iter" src="img/iter-logo.png" alt="Logo Iter Store">
    <h1>Manual Técnico</h1>
    <div class="sub">Iter Store — Instalação, operação e API GraphQL</div>
    <div class="ver">Versão 1.0 · Outubro de 2026</div>
  </div>
  <div class="signature">
    <img src="img/egsn-logo.png" alt="Logo EGSN">
    <div>
      <p class="small">Sistema projetado e desenvolvido por</p>
      <p><strong>Esmerio Neto</strong> — EGSN</p>
      <p class="small">esmerioneto@gmail.com · © 2026 EGSN. Todos os direitos reservados.</p>
    </div>
  </div>
</section>

<section class="toc">
  <h2>Sumário</h2>
  <ol>
    <li><a href="#visao">Visão geral</a><ol><li>Arquitetura</li><li>Módulos</li></ol></li>
    <li><a href="#instalacao">Instalação e ambiente</a><ol><li>Requisitos</li><li>Passo a passo</li><li>Arquivos de configuração</li><li>Produção</li></ol></li>
    <li><a href="#operacao">Operação</a><ol><li>Tarefas agendadas (cron)</li><li>Webhook do Mercado Pago</li><li>Logs</li><li>Testes</li><li>Backup</li></ol></li>
    <li><a href="#api">A API GraphQL</a><ol><li>Endpoint e formato</li><li>Autenticação</li><li>Cabeçalhos e CORS</li><li>Erros</li><li>Limites</li><li>Envio de arquivos</li></ol></li>
    <li><a href="#catalogo">Exemplos: loja e catálogo</a></li>
    <li><a href="#checkout">Exemplos: compra do visitante</a></li>
    <li><a href="#cliente">Exemplos: conta do cliente</a></li>
    <li><a href="#admin">Exemplos: integração do admin</a><ol><li>Login</li><li>Pedidos</li><li>Produtos e estoque</li><li>Relatórios</li></ol></li>
    <li><a href="#erros">Códigos de erro</a></li>
    <li><a href="#referencia">Referência das consultas e mutações</a></li>
  </ol>
  <div class="tip"><b>Exemplos reais</b>Todas as requisições deste manual foram executadas contra a API desta instalação em 20/10/2026, e as respostas são as que ela devolveu (dentro de uma transação desfeita depois: nada ficou gravado). Listas longas foram cortadas ("… mais N") e os tokens, abreviados.</div>
</section>

<section id="visao">
  <h2>1. Visão geral</h2>
  <h3>1.1 Arquitetura</h3>
  <p>O Iter Store é uma aplicação PHP sem framework externo, com PostgreSQL. Tem duas portas de entrada:</p>
  <ul>
    <li><strong>Painel administrativo</strong> (<code>/admin</code>): telas renderizadas no servidor, sessão por cookie, proteção CSRF (<code>form_key</code>) e permissões por perfil (ACL).</li>
    <li><strong>API GraphQL</strong> (<code>/core/graphql/index</code>): um único endpoint para o app, o site e integrações (ERP, atendimento, contabilidade). O contrato completo fica em <code>schema.graphql</code>, na raiz do projeto.</li>
  </ul>
  <p>Os dados flexíveis (atributos de produto, categoria, cliente e endereço) usam o modelo <strong>EAV</strong>: os campos são criados no painel (Catálogo → Atributos) e aparecem na API em <code>attributes</code>, sem mudar o código. As views <code>customer_flat</code> e <code>customer_address_flat</code> entregam o cliente "achatado" para consultas.</p>
  <p>O código fica em módulos (<code>src/vendor/Iter/&lt;Módulo&gt;</code>), cada um com <code>etc/config.json</code> (nome e dependências), <code>etc/acl.json</code> (permissões), <code>etc/menu.json</code>, <code>etc/router.json</code>, <code>Setup/</code> (migrações), <code>GraphQl/</code> (a parte da API) e <code>view/</code>. Módulos próprios do cliente ficam em <code>src/app/code/</code>. Desligar um módulo em Lojas → Configurações → Módulos tira as telas e a API dele, sem apagar os dados.</p>

  <h3>1.2 Módulos</h3>
  <table><tr><th>Módulo</th><th>Depende de</th><th>O que faz</th></tr>
  {''.join(f"<tr><td><strong>{n}</strong></td><td>{d}</td><td>{t}</td></tr>" for n, d, t in MODULES)}
  </table>
  <p>Core, Router, Admin, Config e Mail são obrigatórios. Os outros são ligados em <code>src/etc/config.json</code> (pela tela de módulos); o painel recusa desligar um módulo do qual outro ligado depende.</p>
</section>

<section id="instalacao">
  <h2>2. Instalação e ambiente</h2>
  <h3>2.1 Requisitos</h3>
  <ul>
    <li>PHP 8.1 ou superior com <code>pdo_pgsql</code>, <code>mbstring</code>, <code>curl</code>, <code>gd</code>, <code>zip</code> e <code>openssl</code>.</li>
    <li>Composer. Bibliotecas: webonyx/graphql-php (API), symfony/mailer (e-mail), guzzlehttp/guzzle (Mercado Pago, Melhor Envio, Correios), openspout/openspout (Excel) e dompdf/dompdf (PDF dos relatórios).</li>
    <li>PostgreSQL 15 (há um <code>docker-compose.yml</code> pronto para desenvolvimento, ouvindo só em 127.0.0.1:5432; em produção, PostgreSQL instalado no servidor ou gerenciado).</li>
    <li>Para o backup: <code>restic</code> e o <code>pg_dump</code>/<code>psql</code> da mesma versão do PostgreSQL ou mais nova (<code>postgresql-client-15</code>).</li>
  </ul>
  <h3>2.2 Passo a passo</h3>
  <pre class="sh">composer install
docker compose up -d                                  # PostgreSQL
cp src/etc/database.ini.sample src/etc/database.ini   # credenciais do banco
cp src/etc/mail.ini.sample src/etc/mail.ini           # SMTP
php bin/migrate.php up                                # cria e atualiza as tabelas
php bin/create-admin.php admin 'SenhaForte123' admin@loja.com.br
php -S localhost:8080 -t public                       # só para desenvolvimento</pre>
  <p>O painel abre em <code>http://localhost:8080/admin</code> e a API em <code>http://localhost:8080/core/graphql/index</code>. Depois de ligar um módulo pela primeira vez, rode <code>php bin/migrate.php up</code> de novo. <code>php bin/migrate.php down</code> desfaz a última migração.</p>
  <h3>2.3 Arquivos de configuração</h3>
  <table><tr><th>Arquivo</th><th>Conteúdo</th></tr>
    <tr><td><code>src/etc/database.ini</code></td><td>Host, porta, banco, usuário e senha do PostgreSQL. Fora do Git.</td></tr>
    <tr><td><code>src/etc/mail.ini</code></td><td>Transporte de e-mail (SMTP ou <code>log</code>, que grava os e-mails em <code>var/mail</code>). Fora do Git.</td></tr>
    <tr><td><code>src/etc/env.ini</code></td><td>A chave que criptografa os segredos gravados no banco (Access Token do Mercado Pago, senha dos Correios…). Criado no primeiro uso. Entra no backup e no kit de recuperação: sem ele, esses segredos (e a senha do backup) não podem ser lidos.</td></tr>
    <tr><td><code>src/etc/config.json</code></td><td>Os módulos ligados. Alterado pela tela de módulos.</td></tr>
    <tr><td><code>schema.graphql</code></td><td>O contrato da API. Regere com <code>php bin/schema-dump.php</code> depois de mudar a API (o teste do admin falha se ele estiver desatualizado).</td></tr>
  </table>
  <p>As demais configurações (prazos, limites, credenciais de pagamento e frete, CORS, limites da API) ficam no banco (<code>core_config_data</code>) e são editadas em Lojas → Configurações, por padrão ou por website.</p>
  <h3>2.4 Produção</h3>
  <ul>
    <li>Raiz web apontando para <code>public/</code>; tudo o mais fica fora do alcance do navegador. Todas as requisições vão para <code>public/index.php</code> (no Nginx: <code>try_files $uri /index.php?$query_string;</code>).</li>
    <li>HTTPS obrigatório: o token da API vai no cabeçalho de cada requisição. Com o certificado instalado, ligue Configurações → Geral → <em>Usar só HTTPS</em>: páginas em http são redirecionadas, a API em http é recusada (403), o navegador recebe HSTS e o cookie do painel passa a ser <code>Secure</code>.</li>
    <li>Atrás de um balanceador, proxy ou CDN, informe os IPs dele em Configurações → Geral → <em>Proxies confiáveis</em>. Sem isso, todos os clientes parecem vir do IP do proxy e o limite por IP bloqueia todo mundo junto; com isso, o IP do cliente e o HTTPS vêm de <code>X-Forwarded-For</code> e <code>X-Forwarded-Proto</code> (só quando enviados pelo proxy).</li>
    <li>Crie uma chave em Sistema → Chaves da API para cada app e site, configure-os para enviá-la e ligue <em>Exigir chave do aplicativo</em>.</li>
    <li><code>display_errors</code> desligado no PHP; os erros vão para <code>var/log</code>.</li>
    <li><code>var/</code> (logs, cache, sessões, e-mails) e <code>public/media/</code> (imagens enviadas) graváveis pelo usuário do PHP; <code>public/media/</code> entra no backup junto com o banco e o <code>src/etc</code> (3.5).</li>
    <li>Backup: o cron do <code>bin/backup.php</code> ativo, um destino externo em Configurações → Backup e o kit de recuperação guardado fora do servidor (3.5).</li>
    <li>Em CORS, informe só os sites que podem chamar a API pelo navegador.</li>
    <li><code>src/etc/database.ini</code> e <code>mail.ini</code> com permissão 600 (só o usuário do PHP lê), como o <code>env.ini</code>.</li>
    <li>O painel já envia <code>X-Frame-Options: DENY</code>, CSP <code>frame-ancestors 'none'</code>, <code>nosniff</code> e <code>Referrer-Policy: same-origin</code>. A sessão do painel termina depois de 8 h sem uso e 12 h desde o login (Configurações → Segurança do admin), e quando a senha muda. Sair é um POST com <code>form_key</code> (<code>/admin/auth/logoutpost</code>).</li>
  </ul>
</section>

<section id="operacao">
  <h2>3. Operação</h2>
  <h3>3.1 Tarefas agendadas (cron)</h3>
  <table><tr><th>Quando</th><th>Script</th><th>Para quê</th></tr>
  {''.join(f"<tr><td><code>{w}</code></td><td><code>{s}</code></td><td>{e(t)}</td></tr>" for w, s, t in CRON)}
  </table>
  <pre class="sh">* * * * *   php /srv/iterstore/bin/mail-worker.php
* * * * *   /srv/iterstore/bin/mercadopago-sync-loop.sh
15 * * * *  php /srv/iterstore/bin/order-clean.php
10 0 * * *  php /srv/iterstore/bin/log-clean.php
20 0 * * *  php /srv/iterstore/bin/quote-clean.php
30 7 * * *  php /srv/iterstore/bin/report-mail.php
* * * * *   php /srv/iterstore/bin/backup.php >> /srv/iterstore/var/log/backup.log 2>&1</pre>
  <p>Outros scripts: <code>bin/create-admin.php</code> (primeiro admin, ou recuperar o acesso), <code>bin/schema-dump.php</code> (regera o <code>schema.graphql</code>), <code>bin/migrate.php</code> e <code>bin/db-bench.php</code> (tempo das listagens EAV).</p>
  <h3>3.2 Webhook do Mercado Pago</h3>
  <p>A única rota fora do GraphQL e do painel: <code>POST https://sua-loja/mercadopago/webhook</code>. Cadastre-a no painel do Mercado Pago (Suas integrações → Webhooks, tópico <em>Order</em>) e copie a "assinatura secreta" para Configurações → Pagamento. Com a assinatura salva, avisos com <code>x-signature</code> inválida recebem 401, e também os assinados há mais de 10 minutos (o <code>ts</code> da assinatura, em segundos ou milissegundos): um aviso capturado e reenviado depois é recusado. O corpo do aviso só diz qual cobrança mudou: o sistema relê a cobrança na API do Mercado Pago, e o cron <code>mercadopago-sync</code> cobre os avisos perdidos.</p>
  <h3>3.3 Logs</h3>
  <ul>
    <li><code>var/log/iter-AAAA-MM-DD.log</code>: erros e avisos do sistema, um arquivo por dia (inclui a causa real de um <code>INTERNAL_ERROR</code> da API). Consulta também em Sistema → Log do sistema.</li>
    <li>Log de operações (tabela <code>log_operation</code>): quem fez o quê no painel ou pela API, com antes e depois, logins e exportações de relatórios de clientes e financeiros.</li>
    <li>E-mails: tabela <code>mail_queue</code>, consultada em Sistema → E-mails enviados.</li>
  </ul>
  <h3>3.4 Testes</h3>
  <pre class="sh">composer test</pre>
  <p>Roda <code>view-selftest</code>, <code>db-selftest</code>, <code>admin-selftest</code> (sobe um <code>php -S</code> e testa o painel e a API de ponta a ponta), <code>promotion-selftest</code>, <code>report-selftest</code>, <code>backup-selftest</code> (um backup de verdade num repositório descartável, com restauração e zip) e <code>shipping-provider-selftest</code> (provedores de frete sem rede). Usam o banco real: criam registros <code>selftest_*</code> ou trabalham numa transação desfeita, e limpam tudo ao terminar. Mantêm ligados só os módulos que estavam ligados antes.</p>
  <h3>3.5 Backup</h3>
  <p>Módulo <code>Iter_Backup</code>, com <a href="https://restic.net">restic</a>. Cada cópia leva o banco em SQL puro (<code>pg_dump --no-owner --no-privileges</code>, em <code>var/backup/dump/banco.sql</code>, apagado depois), o <code>commit.txt</code> do código, <code>public/media</code> e <code>src/etc</code>. O restic compacta (zstd, nível máximo), cifra e só guarda o que mudou: com o banco de 17 MB e 4,5 MB de imagens, a primeira cópia ocupa 2,6 MB e cada nova cerca de 0,1 MB.</p>
  <ul>
    <li><strong>Configurações → Backup</strong> (<code>system/backup/*</code>, permissão <code>Iter_Backup::backup</code>): ligado, intervalo (1 h a 1 semana, com horário e dia), retenção (<code>--keep-hourly/daily/weekly/monthly</code>, padrão 24/7/4/12; todos em 0 é recusado), e-mail de falha, avisos no Dashboard (<code>system/backup/dashboard_warnings</code>; desligado, o Dashboard não mostra nada do backup) e destino: pasta local (padrão <code>var/backup/repo</code>, nunca em <code>public/</code>), Backblaze B2 (<code>b2:&lt;bucket&gt;:iterstore</code>) ou S3 compatível (<code>s3:https://&lt;endpoint&gt;/&lt;bucket&gt;/iterstore</code>). A chave secreta é gravada cifrada.</li>
    <li><strong>Senha do repositório:</strong> gerada no primeiro uso (64 hex), gravada cifrada em <code>system/backup/repo_password</code>. Credenciais vão ao restic pelo ambiente (<code>RESTIC_PASSWORD</code>, <code>B2_ACCOUNT_*</code>, <code>AWS_*</code>), a senha do banco por <code>PGPASSWORD</code>; nada vai na linha de comando.</li>
    <li><strong>Cron:</strong> a cada minuto, com <code>flock</code>. Copia quando vence o intervalo ou existe <code>var/backup/run-now</code> (botão "Fazer backup agora"). Depois de uma falha, espera 15 minutos. Limpeza (<code>forget --prune</code>) uma vez por dia; teste de restauração uma vez a cada 30 dias.</li>
    <li><strong>Situação:</strong> <code>var/backup/status.json</code> (última cópia, cópias, espaço, erro, teste), lido por Sistema → Backup e pelo Dashboard. O painel nunca chama o restic.</li>
    <li><strong>Teste de restauração:</strong> o <code>banco.sql</code> da última cópia é restaurado num banco <code>&lt;banco&gt;_verify</code> (o usuário do banco precisa de <code>CREATEDB</code>), conferido (todas as tabelas, e pedidos, clientes e produtos ao menos os contados antes do dump) e apagado.</li>
    <li><strong>Kit de recuperação</strong> (Sistema → Backup → Abrir o kit): só o perfil Administrador, pede a senha de novo, fica no log de operações (<code>backup_kit</code>). Mostra a senha do restic e a <code>crypt_key</code> do <code>env.ini</code>. Guardar no gerenciador de senhas da empresa e impresso, nunca por e-mail.</li>
  </ul>
  <pre class="sh">php bin/backup.php                    # o que o cron roda
php bin/backup.php now antes-migracao # uma cópia agora, com marcação
php bin/backup.php verify             # teste de restauração agora
php bin/backup.php zip [cópia]        # .zip de uma cópia (latest) em var/backup/, permissão 600</pre>
  <h4>Restaurar num servidor novo</h4>
  <pre class="sh">export RESTIC_REPOSITORY='b2:bucket:iterstore'   # ou s3:https://..., ou a pasta
export RESTIC_PASSWORD='...'                     # do kit de recuperação
export B2_ACCOUNT_ID='...' B2_ACCOUNT_KEY='...'  # ou AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY
restic snapshots                                 # escolha a cópia (latest = a última)
restic restore latest --target /tmp/r
createdb loja
grep -v '^SET transaction_timeout' /tmp/r/&lt;raiz&gt;/var/backup/dump/banco.sql | psql -v ON_ERROR_STOP=1 -d loja
git checkout $(cat /tmp/r/&lt;raiz&gt;/var/backup/dump/commit.txt)
cp -a /tmp/r/&lt;raiz&gt;/public/media public/ &amp;&amp; cp -a /tmp/r/&lt;raiz&gt;/src/etc/. src/etc/
composer install &amp;&amp; php bin/migrate.php up</pre>
  <p><code>&lt;raiz&gt;</code> é o caminho do sistema no servidor antigo (o restic guarda caminhos absolutos). Confira o <code>crypt_key</code> do <code>src/etc/env.ini</code> restaurado com o do kit e ajuste <code>database.ini</code> para o banco novo. A linha <code>SET transaction_timeout</code> só existe em dumps de pg_dump 17+ e é desconhecida antes do PostgreSQL 17.</p>
</section>

<section id="api">
  <h2>4. A API GraphQL</h2>
  <h3>4.1 Endpoint e formato</h3>
  <p>Toda chamada é um <code>POST</code> para <code>/core/graphql/index</code> com um JSON <code>{{"query": "...", "variables": {{...}}}}</code>. A resposta é sempre HTTP 200 com <code>data</code> e/ou <code>errors</code>, exceto token ou chave inválidos (401), excesso de requisições (429) e chamada sem HTTPS com "Usar só HTTPS" ligado (403).</p>
  <pre class="sh">curl -s https://sua-loja/core/graphql/index \\
  -H 'Content-Type: application/json' \\
  -H 'X-Api-Key: itk_…'   # quando a chave é exigida \\
  -d '{{"query":"{{ websites {{ id name isDefault }} }}"}}'</pre>
  <pre class="json">{e(json.dumps(EX['websites']['response']['data']['websites'], ensure_ascii=False))}</pre>
  <h4>Com variáveis (JavaScript)</h4>
  <pre class="gql">const API = 'https://sua-loja/core/graphql/index';

async function gql(query, variables = {{}}, token = null) {{
  const res = await fetch(API, {{
    method: 'POST',
    headers: {{
      'Content-Type': 'application/json',
      'X-Api-Key': process.env.API_KEY,   // Sistema → Chaves da API
      ...(token ? {{ Authorization: `Bearer ${{token}}` }} : {{}}),
    }},
    body: JSON.stringify({{ query, variables }}),
  }});
  const json = await res.json();
  if (json.errors?.length) {{
    const err = new Error(json.errors[0].message);
    err.code = json.errors[0].extensions?.code;   // decida pelo código, não pela mensagem
    throw err;
  }}
  return json.data;
}}

const {{ product }} = await gql(
  'query P($sku: String!) {{ product(sku: $sku) {{ name priceRange {{ min max }} }} }}',
  {{ sku: 'MODA-CALCA-JEANS' }},
);</pre>
  <h4>Em PHP (Guzzle, já instalado no projeto)</h4>
  <pre class="gql">$http = new GuzzleHttp\\Client(['base_uri' => 'https://sua-loja/']);
$res = $http->post('core/graphql/index', [
    'headers' => ['Authorization' => 'Bearer ' . $token], // com o token de admin, a chave não é pedida
    'json' => [
        'query' => 'query ($s: OrderStatus) {{ orders(status: $s, pageSize: 50) {{ total items {{ number total }} }} }}',
        'variables' => ['s' => 'processing'],
    ],
]);
$body = json_decode((string) $res->getBody(), true);
if (isset($body['errors'])) {{
    throw new RuntimeException($body['errors'][0]['extensions']['code'] . ': ' . $body['errors'][0]['message']);
}}</pre>
  <p>Para explorar a API, use <span class="path">Sistema → API (GraphiQL)</span> no painel: autocompletar, documentação de cada campo e execução com a sessão do admin logado.</p>

  <h3>4.2 Autenticação</h3>
  <table><tr><th>Quem</th><th>Como entra</th><th>Validade</th></tr>
    <tr><td><strong>Visitante</strong></td><td>Sem token. O carrinho é identificado pelo <code>cartId</code> devolvido por <code>createCart</code>.</td><td>O carrinho some depois dos dias de Configurações → Vendas.</td></tr>
    <tr><td><strong>Cliente da loja</strong></td><td><code>generateCustomerToken</code> (ou <code>registerCustomer</code>) devolve um token. Envie <code>Authorization: Bearer &lt;token&gt;</code>. <code>refreshCustomerToken</code> renova; <code>revokeCustomerToken</code> sai.</td><td>Configurações → Clientes (padrão 720 h).</td></tr>
    <tr><td><strong>Aplicativo ou site</strong></td><td>Uma chave criada em <span class="path">Sistema → Chaves da API</span>, enviada no cabeçalho <code>X-Api-Key</code> de toda chamada. Exigida quando "Exigir chave do aplicativo" está ligado em Configurações → API; aí, sem ela, a resposta é HTTP 401 <code>API_KEY_INVALID</code>.</td><td>Até ser revogada no painel.</td></tr>
    <tr><td><strong>Admin / integração</strong></td><td><code>generateAdminToken</code> com o usuário e a senha de um usuário do painel. O token tem as mesmas permissões do perfil dele. <code>revokeAdminToken</code> sai.</td><td>Configurações → Segurança do admin (padrão 4 h). Trocar a senha invalida os tokens anteriores.</td></tr>
  </table>
  <div class="tip"><b>Usuário próprio para cada integração</b>Crie um usuário do painel para cada sistema integrado (ex.: "integracao-erp"), com um perfil que só tenha as permissões necessárias. Tudo o que ele fizer fica no log de operações com o nome dele.</div>
  <p>Com a chave exigida, o visitante e o cliente precisam dela (o login do admin também: <code>generateAdminToken</code> vai com a chave). Já com o token de admin em mãos, ou logado no painel (GraphiQL), a chave não é pedida. Uma chave por aplicativo: se uma vazar, revogue só ela e publique o app com a nova.</p>
  <p>Um token de cliente nunca passa pelos campos do admin (a resposta é <code>UNAUTHENTICATED</code>). Um token inválido ou vencido é recusado com HTTP 401 e o código <code>TOKEN_INVALID</code>, mesmo em campos públicos: descarte-o e faça login de novo.</p>

  <h3>4.3 Cabeçalhos e CORS</h3>
  <table><tr><th>Cabeçalho</th><th>Uso</th></tr>
    <tr><td><code>Content-Type: application/json</code></td><td>Obrigatório (exceto no envio de arquivos). Com a sessão do painel (GraphiQL), outro tipo dá 415 <code>UNSUPPORTED_MEDIA_TYPE</code>.</td></tr>
    <tr><td><code>Authorization: Bearer &lt;token&gt;</code></td><td>Cliente ou admin.</td></tr>
    <tr><td><code>X-Api-Key</code></td><td>A chave do aplicativo ou site (Sistema → Chaves da API).</td></tr>
    <tr><td><code>X-Sales-Channel</code></td><td>No <code>placeOrder</code>: o canal de venda gravado no pedido ("Site", "App mobile", "Instagram"…). Sem ele, "App mobile". Aparece nos relatórios de canal.</td></tr>
  </table>
  <p>Para chamar a API pelo navegador a partir de outro domínio, cadastre o site em Configurações → Geral → "Sites que podem usar a API pelo navegador (CORS)" (um por linha, ou <code>*</code>). Apps nativos e servidores não precisam de CORS. O CORS só controla navegadores: contra programas em servidores, valem a chave do aplicativo e os limites por IP. O login pela API nunca usa cookie: só o token.</p>

  <h3>4.4 Erros</h3>
  <p>Todo erro traz <code>extensions.code</code>. A mensagem é em português e pode ser mostrada ao usuário, mas a lógica do cliente deve decidir pelo código. Exemplo, um produto com grade colocado no carrinho:</p>
  <pre class="json">{e(dump(trim(EX['addConfigurable']['response'])))}</pre>
  <p>Erros de sintaxe e de campo inexistente (testados no servidor):</p>
  <pre class="json">{{"errors":[{{"message":"Syntax Error: Expected Name, found &lt;EOF&gt;","extensions":{{"code":"GRAPHQL_PARSE_FAILED"}}}}]}}
{{"errors":[{{"message":"Cannot query field \\"slug\\" on type \\"Website\\".","extensions":{{"code":"GRAPHQL_VALIDATION_FAILED"}}}}]}}</pre>
  <p>Token inválido (HTTP 401):</p>
  <pre class="json">{{"errors":[{{"message":"Token inválido ou expirado. Faça login de novo.","extensions":{{"code":"TOKEN_INVALID"}}}}]}}</pre>
  <p>A lista dos códigos está no capítulo 9.</p>

  <h3>4.5 Limites</h3>
  <table><tr><th>Limite</th><th>Padrão</th><th>Onde muda</th></tr>
    <tr><td>Profundidade da consulta</td><td>15 níveis</td><td>Configurações → API</td></tr>
    <tr><td>Complexidade da consulta</td><td>1.500 pontos sem login de admin; o admin, sem limite. Cada campo conta 1; uma lista paginada (<code>products</code>, <code>orders</code>, <code>myOrders</code>, <code>customers</code>…) conta os campos de dentro × o <code>pageSize</code> (até 100); o <code>report</code>, × <code>pageSize</code> ÷ 10. A consulta mais cara do app (<code>myOrders</code> com 20 por página) fica perto de 660</td><td>Configurações → API</td></tr>
    <tr><td>Tamanho da chamada (corpo JSON)</td><td>64 KB sem login de admin, 8 MB com; acima, HTTP 413 <code>PAYLOAD_TOO_LARGE</code>. O envio de arquivos segue o limite do PHP</td><td>Configurações → API</td></tr>
    <tr><td>Introspecção (<code>__schema</code>) sem login de admin</td><td>desligada (o admin logado sempre tem, para o GraphiQL)</td><td>Configurações → API</td></tr>
    <tr><td>Requisições à API por IP (o admin não entra)</td><td>240 por minuto; acima, HTTP 429 <code>TOO_MANY_REQUESTS</code></td><td>Configurações → API</td></tr>
    <tr><td>Cotações de frete (<code>shippingQuotes</code>) por IP</td><td>20 por minuto</td><td>Configurações → API</td></tr>
    <tr><td>Carrinhos criados / pedidos fechados / consultas de pedido de visitante, por IP</td><td>30 / 10 / 10 por minuto</td><td>Configurações → Vendas</td></tr>
    <tr><td>Login do admin (API e painel) e "Esqueci a senha"</td><td>10 por minuto por IP</td><td>Configurações → Segurança do admin</td></tr>
    <tr><td>Login e cadastro de cliente</td><td>10 por minuto por IP</td><td>Configurações → Clientes</td></tr>
    <tr><td>Mensagens do cliente (<code>sendCustomerMessage</code>)</td><td>10 por minuto por cliente; as respostas da loja não têm limite</td><td>Configurações → Clientes</td></tr>
    <tr><td><code>pageSize</code> das listas</td><td>padrão 20; relatórios até 1.000</td><td>—</td></tr>
  </table>
  <p>Os limites por IP contam um endereço IPv6 pelo bloco <code>/64</code> (quem tem um bloco inteiro não escapa trocando de endereço); o IPv4 conta pelo endereço.</p>
  <p>Uma consulta acima dos limites é recusada antes de rodar:</p>
  <pre class="json">{{"errors":[{{"message":"Max query depth should be 15 but got 20.","extensions":{{"code":"QUERY_LIMIT_EXCEEDED"}}}}]}}</pre>

  <h3>4.6 Envio de arquivos</h3>
  <p><code>uploadProductImage</code> e <code>setCategoryBanners</code> recebem arquivos pelo <em>GraphQL multipart request spec</em> (<code>multipart/form-data</code> com <code>operations</code>, <code>map</code> e o arquivo). Exige o token do admin no cabeçalho: com só o cookie, o envio é recusado (proteção contra CSRF).</p>
  <pre class="sh">curl https://sua-loja/core/graphql/index \\
  -H "Authorization: Bearer $TOKEN" \\
  -F operations='{{"query":"mutation ($f: Upload!) {{ uploadProductImage(productId: \\"1918\\", code: \\"imagem\\", file: $f) {{ sku }} }}","variables":{{"f":null}}}}' \\
  -F map='{{"0":["variables.f"]}}' \\
  -F 0=@foto.jpg</pre>
</section>

<section id="catalogo">
  <h2>5. Exemplos: loja e catálogo</h2>
  <p>Campos públicos, sem token: é o que o app e o site usam para montar as telas. O produto inativo e os atributos ocultos não aparecem. <code>websiteId</code> omitido = o website padrão.</p>
  {example('websites', 'Websites e dados da loja', 'Os dados da loja (nome, telefone, endereço) vêm de Configurações → Loja; aqui eles ainda não foram preenchidos.')}
  {example('categories', 'Árvore de categorias', 'Só as ativas do website, com as filhas em <code>children</code>.', keep=2)}
  {example('products', 'Busca de produtos com paginação', 'Busca por nome ou SKU. <code>aggregations</code> traz a faixa de preço e os valores dos atributos filtráveis do resultado, para montar os filtros da tela. Filtros por atributo: <code>filters: [{code: "color", values: ["azul"]}]</code>; por preço: <code>priceFrom</code> e <code>priceTo</code>.', keep=2)}
  {example('productBySku', 'Produto com grade (variações)', 'Um produto <code>CONFIGURABLE</code> traz os atributos da grade e cada variação com o seu SKU, estoque e opções. É o SKU da variação que vai para o carrinho.', keep=2)}
  {example('route', 'Resolver um endereço da loja', 'Para o site: o que abre em <code>/calca-jeans-skinny</code> (produto ou categoria).')}
</section>

<section id="checkout">
  <h2>6. Exemplos: compra do visitante</h2>
  <p>A sequência completa, do carrinho ao pedido. O cliente logado faz o mesmo sem <code>cartId</code> (usa o carrinho dele) e sem <code>setCartGuestInfo</code>.</p>
  <ol class="steps">
    <li><code>createCart</code> → guarde o <code>cartId</code> (no app, no armazenamento local).</li>
    <li><code>addToCart</code> / <code>updateCartItem</code> / <code>removeCartItem</code>.</li>
    <li><code>setCartGuestInfo</code> (e-mail, nome, CPF/CNPJ).</li>
    <li><code>setCartShippingAddress</code> → <code>availableShippingMethods</code> → <code>setCartShippingMethod</code>.</li>
    <li><code>availablePaymentMethods</code> → <code>setCartPaymentMethod</code>. Cupom: <code>applyCartCoupon</code>.</li>
    <li><code>placeOrder</code> com o <code>expectedTotal</code> que o cliente viu.</li>
    <li>Mercado Pago: <code>payOrder</code> (Pix: QR Code; cartão: token do SDK).</li>
  </ol>
  {example('createCart', '1. Criar o carrinho')}
  {example('addToCart', '2. Adicionar um produto', '<code>quantity</code> soma ao que já estiver no carrinho. Cada item traz <code>error</code> quando algo mudou (sem estoque, sem preço): <code>hasErrors</code> impede o pedido.')}
  {example('addConfigurable', 'Erro: produto com grade', 'Enviar o SKU do produto pai é recusado; envie o da variação.')}
  {example('guestInfo', '3. Dados do visitante', 'O CPF/CNPJ é validado e guardado só com os dígitos.')}
  {example('shippingQuotes', 'Cotação de frete antes do endereço', 'Para o "calcule o frete" da tela do produto ou do carrinho, só com o CEP.')}
  {example('shippingAddress', '4. Endereço de entrega e formas de entrega', 'O CEP pode ir com ou sem máscara. As formas de entrega dependem do endereço e do carrinho (faixas de frete, Melhor Envio, retirada na loja).')}
  {example('shippingMethod', 'Escolher a entrega', 'Depois da entrega, <code>availablePaymentMethods</code> lista as formas de pagamento válidas para este carrinho (nesta loja, as do Mercado Pago).')}
  {example('paymentMethod', '5. Escolher o pagamento')}
  {example('totalChanged', 'Erro: o total mudou', '<code>expectedTotal</code> protege o cliente de pagar um valor diferente do que viu (preço, promoção ou frete mudaram no meio). Mostre o carrinho de novo e peça a confirmação.')}
  {example('placeOrder', '6. Fechar o pedido', 'Reserva o estoque, envia o e-mail "pedido recebido" e devolve o pedido. Chamar de novo com o mesmo carrinho devolve o mesmo pedido (seguro contra clique duplo ou falha de rede). O canal veio do cabeçalho <code>X-Sales-Channel</code>.')}
  {example('guestOrder', 'Acompanhar o pedido do visitante', 'Com o número e o e-mail do pedido (a tela "acompanhe seu pedido").')}
  <h4>7. Pagar pelo Mercado Pago</h4>
  <p><code>payOrder</code> cobra um pedido "Aguardando pagamento". No Pix, devolve o copia e cola (<code>pixQrCode</code>), a imagem (<code>pixQrCodeBase64</code>) e a validade; chamar de novo devolve o mesmo QR enquanto ele valer. No cartão, o app gera o token com o SDK MercadoPago.js V2 (a <code>publicKey</code> vem de <code>mercadopagoSettings</code>) e envia em <code>card</code>; recusado, pode tentar de novo com outro cartão. Este exemplo não foi executado aqui porque cria uma cobrança real no Mercado Pago.</p>
  <ul>
    <li><code>card.paymentMethodId</code> é o que o SDK devolveu em <code>getInstallments</code>. No débito, um cartão de débito vem como <code>debvisa</code>, <code>debmaster</code>, <code>debelo</code>… e é cobrado como <code>debit_card</code> em 1x; um pré-pago vem com a bandeira simples (<code>elo</code>, <code>visa</code>…) e é cobrado como <code>prepaid_card</code>, sem parcelas.</li>
    <li><code>deviceId</code>: o identificador do aparelho que o <code>security.js</code> do Mercado Pago gera (<code>MP_DEVICE_SESSION_ID</code>). Vai no cabeçalho <code>X-meli-session-id</code> e melhora a aprovação. Opcional.</li>
    <li>Cada cobrança leva, além do valor: os itens (SKU, nome, opções, quantidade, preço e a "Categoria dos produtos" das Configurações), o frete como um item "Frete" (a soma dos itens precisa bater com o total; com desconto, o pedido vai como um item só), nome, CPF/CNPJ, e-mail, telefone e endereço do pagador, o "Nome na fatura do cartão" e, para cliente logado, a data do cadastro, se é a primeira compra e a data da última.</li>
    <li>No app Jardim Vera, o SDK roda numa WebView invisível (<code>react-native-webview</code>): bandeira, parcelas, token e <code>deviceId</code> vêm do próprio MercadoPago.js. O número do cartão nunca passa pelo servidor da loja.</li>
  </ul>
  <pre class="gql">mutation {{
  payOrder(number: "RJ000056", email: "maria@exemplo.com.br") {{
    status statusLabel amount
    pixQrCode pixQrCodeBase64 expiresAt
    order {{ status statusLabel }}
  }}
}}

# cartão
mutation ($card: MercadopagoCardInput!, $deviceId: String) {{
  payOrder(number: "RJ000056", email: "maria@exemplo.com.br", card: $card, deviceId: $deviceId) {{
    status statusDetail cardBrand cardNumber installments
    challengeUrl challengeCreq
    order {{ status }}
  }}
}}
# variables: {{ "card": {{ "token": "&lt;token do SDK&gt;", "paymentMethodId": "visa", "installments": 3 }}, "deviceId": "&lt;MP_DEVICE_SESSION_ID&gt;" }}</pre>
  <p>A confirmação chega pelo webhook (e pelo cron de sincronização): o pedido passa para "Pagamento confirmado" (<code>processing</code>). O app consulta o pedido (<code>guestOrder</code> ou <code>myOrder</code>) para mostrar a situação.</p>
</section>

<section id="cliente">
  <h2>7. Exemplos: conta do cliente</h2>
  {example('register', 'Cadastro', 'Devolve o token já logado. Com "Exigir confirmação do e-mail" ligado, <code>token</code> vem nulo e <code>confirmationRequired: true</code>: o cliente confirma pelo link do e-mail (<code>confirmCustomerEmail</code>). Para pessoa jurídica: <code>type: PJ</code>, CNPJ, <code>companyName</code> e inscrição estadual.')}
  {example('customerToken', 'Login')}
  {example('me', 'Dados do cliente logado')}
  {example('noToken', 'Sem token', '<code>me</code> sem login devolve <code>null</code> (não é erro): o app sabe que precisa pedir o login.')}
  {example('createAddress', 'Cadastrar endereço', 'Devolve a lista de endereços. O endereço padrão de entrega pode ser usado no carrinho com <code>setCartShippingAddress(customerAddressId: ...)</code>.')}
  {example('customerCart', 'Carrinho do cliente', 'Sem <code>cartId</code>: o cliente tem um carrinho por website, que acompanha o login em qualquer aparelho.')}
  {example('mergeCarts', 'Juntar o carrinho de visitante ao fazer login', 'O visitante montou um carrinho e depois entrou: <code>mergeCarts</code> com o <code>cartId</code> antigo leva os itens para o carrinho do cliente.')}
  {example('myOrders', 'Meus pedidos', 'Paginado. <code>myOrder(number:)</code> traz um pedido com itens, endereço, rastreio e o histórico visível ao cliente.')}
</section>

<section id="admin">
  <h2>8. Exemplos: integração do admin</h2>
  <p>Para ERP, aplicativo de atendimento, contabilidade e automações. Tudo com o token do admin; cada ação grava no histórico do pedido e no log de operações o usuário do token.</p>
  <h3>8.1 Login</h3>
  {example('adminToken', 'Gerar o token')}
  {example('adminWrong', 'Senha errada', 'A mesma mensagem para usuário inexistente, senha errada ou conta bloqueada (não revela quais usuários existem). Depois do limite por IP, o código vira <code>TOO_MANY_REQUESTS</code> (HTTP 429).')}
  {example('currentAdmin', 'Quem é o token')}
  {example('ordersForbidden', 'Token de cliente num campo do admin', 'O token de cliente nunca vale como admin.')}
  <h3>8.2 Pedidos</h3>
  {example('orders', 'Listar pedidos', 'Filtros: <code>status</code>, <code>websiteId</code>, <code>customerId</code>, <code>salesChannel</code>, <code>paymentMethod</code>, <code>cancelReason</code>, <code>search</code> (número, nome, e-mail, CPF), <code>from</code>/<code>to</code> (AAAA-MM-DD), ordenação e página. <code>amount</code> é a soma do resultado.')}
  {example('confirmPayment', 'Confirmar o pagamento', 'Aguardando pagamento → Pagamento confirmado. Avisa o cliente por e-mail. Exige "Pedidos: confirmar pagamento".')}
  {example('shipOrder', 'Registrar o envio', 'Pagamento confirmado → Enviado: baixa o estoque da origem (a sugerida, se <code>sourceCode</code> for omitido) e manda o rastreio ao cliente. Depois, <code>completeOrder</code> marca como Concluído.')}
  <h4>Cancelar</h4>
  <pre class="gql">mutation {{
  cancelOrder(id: "3947", reason: OUT_OF_STOCK, comment: "Sem a cor azul", notify: true, refund: true) {{
    number status cancelReason history {{ status comment }}
  }}
}}</pre>
  <p>Motivos: <code>CUSTOMER</code>, <code>UNPAID</code>, <code>OUT_OF_STOCK</code>, <code>FRAUD</code>, <code>ORDER_ERROR</code>, <code>OTHER</code> (este exige <code>comment</code>). Pedido pago pelo Mercado Pago é estornado lá com <code>refund: true</code>; pago de outra forma exige <code>refundNote</code> (como o dinheiro foi devolvido). A reserva de estoque volta.</p>
  {example('salesSummary', 'Resumo de vendas por situação', 'Quantos pedidos e quanto em cada situação, no período.', keep=5)}
  <h3>8.3 Produtos e estoque</h3>
  {example('attributes', 'Os atributos do produto', 'Os códigos que podem ir em <code>attributes</code> ao criar ou alterar um produto. O admin vê também os ocultos.', keep=4)}
  {example('createProduct', 'Criar um produto', 'Preço, custo, peso e os outros campos vão em <code>attributes</code>, como texto (decimal com ponto). Precisa de ao menos uma categoria de cada website. O custo exige a permissão "Ver e editar o custo" e nunca aparece na API pública. Produto com grade: <code>type: CONFIGURABLE</code> com <code>variantAttributes</code>, e depois <code>saveVariants</code>.')}
  {example('setStock', 'Definir o estoque', 'A quantidade física por origem (código da origem em Catálogo → Estoque). O vendável desconta as reservas dos pedidos ainda não enviados.')}
  {example('updateProduct', 'Alterar o preço', 'Só os campos enviados mudam. A alteração entra no log de operações com antes e depois. Para muitos custos de uma vez, há também a importação por planilha no painel.')}
  <h3>8.4 Relatórios</h3>
  {example('reports', 'Os relatórios disponíveis', 'Só os que o perfil do token pode abrir, com os filtros de cada um.', keep=3)}
  {example('report', 'Um relatório', 'Os mesmos números da tela. <code>rows</code> traz uma lista por linha, na ordem de <code>columns</code>, com os valores sem formatação (o <code>type</code> diz como mostrar: <code>money</code>, <code>pct</code> como fração, <code>date</code>…). <code>tiles</code> são os indicadores já formatados. Consultar relatórios de clientes ou financeiros grava no log de operações.', keep=3)}
  {example('revokeAdmin', 'Sair', 'O token para de valer na hora.')}
</section>

<section id="erros">
  <h2>9. Códigos de erro</h2>
  <p>Os mais comuns, por assunto. A descrição de cada campo no <code>schema.graphql</code> lista os códigos específicos dele.</p>
  {errors_html()}
</section>

<section id="referencia">
  <h2>10. Referência das consultas e mutações</h2>
  <p>Todas as entradas da API desta versão ({len(q_fields)} consultas e {len(m_fields)} mutações), com quem pode chamar e o início da descrição do <code>schema.graphql</code>, que tem os argumentos e os tipos completos. "admin: X" = token do admin com a permissão X no perfil (o perfil Administradores tem todas). "público" = sem login, mas com a chave do aplicativo quando ela é exigida.</p>
  <h3>10.1 Consultas (query)</h3>
  {ref_table('Query')}
  <h3>10.2 Mutações (mutation)</h3>
  {ref_table('Mutation')}
</section>

<section class="final">
  <img src="img/egsn-logo.png" alt="Logo EGSN">
  <h3>Iter Store</h3>
  <p>Projetado e desenvolvido por <strong>Esmerio Neto</strong></p>
  <p>EGSN · esmerioneto@gmail.com</p>
  <p style="color:#6b6b6b;font-size:9pt;margin-top:24px">© 2026 EGSN. Todos os direitos reservados.</p>
</section>

</main>
</body>
</html>
'''
open(OUT, 'w').write(doc)
print('ok', OUT, 'campos sem descrição:', missing)
