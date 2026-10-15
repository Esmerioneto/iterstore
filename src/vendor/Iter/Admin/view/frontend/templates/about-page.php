<style>
    .iter-about .iter-about-features {
        list-style: none;
        margin: 1rem 0 0;
        padding: 0;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
        gap: 1rem;
    }
    .iter-about .iter-about-features > li {
        display: flex;
        align-items: flex-start;
        gap: .9rem;
        padding: 1rem;
        border: 1px solid var(--border-color, #e5e7eb);
        border-radius: .75rem;
        min-width: 0;
    }
    .iter-about .iter-about-feature-icon {
        display: inline-flex;
        flex: 0 0 2.75rem;
        width: 2.75rem;
        height: 2.75rem;
        align-items: center;
        justify-content: center;
        border-radius: .65rem;
        background: var(--primary-light, #eef2ff);
        color: var(--primary-color, #4338ca);
    }
    .iter-about .iter-about-feature-icon svg {
        display: block;
        width: 1.5rem;
        height: 1.5rem;
        stroke: currentColor;
        fill: none;
        stroke-width: 1.8;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .iter-about .iter-about-feature-copy {
        min-width: 0;
    }
    .iter-about .iter-about-feature-copy strong {
        display: block;
        margin: 0 0 .4rem;
        line-height: 1.4;
    }
    .iter-about .iter-about-feature-copy > span {
        display: block;
        line-height: 1.65;
    }
    .iter-about .iter-about-content > section {
        margin-bottom: 1.75rem;
    }
    .iter-about .iter-about-signoff {
        margin-top: 2rem;
        padding: 1.5rem;
        border: 1px solid var(--border-color, #e5e7eb);
        border-radius: .9rem;
        text-align: center;
    }
    .iter-about .iter-about-company-logo {
        display: block;
        width: 6rem;
        height: auto;
        max-height: 6.5rem;
        object-fit: contain;
        margin: 0 auto 1rem;
    }
    .iter-about .iter-about-signoff > strong {
        display: block;
        margin-bottom: .35rem;
        font-size: 1rem;
    }
    .iter-about .iter-about-signoff > p {
        margin: .5rem 0;
    }
    .iter-about .iter-about-contact {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        color: var(--primary-color, #4338ca);
        text-decoration: none;
        overflow-wrap: anywhere;
    }
    .iter-about .iter-about-contact:hover {
        text-decoration: underline;
    }
    .iter-about .iter-about-copyright {
        margin-top: 1rem !important;
        padding-top: 1rem;
        border-top: 1px solid var(--border-color, #e5e7eb);
        font-size: .875rem;
        color: var(--text-muted, #6b7280);
    }
    @media (max-width: 600px) {
        .iter-about .iter-about-features {
            grid-template-columns: 1fr;
        }
    }
</style>

<section class="iter-about">
    <header class="iter-about-hero">
        <p class="iter-about-eyebrow">PLATAFORMA DE COMÉRCIO DIGITAL</p>
        <h2>Sobre o IterStore</h2>
        <p class="iter-about-intro">O IterStore é uma plataforma modular de e-commerce que centraliza a operação comercial em um backend próprio e permite que diferentes vitrines, aplicativos e serviços se conectem por uma API. O painel administrativo e as interfaces de compra trabalham sobre a mesma base de regras de negócio.</p>
    </header>

    <div class="iter-about-content">
        <section class="iter-about-purpose">
            <h3>Uma abordagem própria para o comércio digital</h3>
            <p>A intenção do IterStore não é simplesmente reproduzir um e-commerce já existente. É aproveitar conceitos consolidados de arquitetura de comércio eletrônico, adaptando-os a uma implementação própria, com módulos independentes, responsabilidades claras e espaço para evoluir conforme as necessidades da operação.</p>
        </section>

        <section>
            <h3>Uma base de comércio, diferentes experiências</h3>
            <p>A plataforma separa as funcionalidades comerciais das interfaces que as utilizam. Assim, a operação pode ser administrada pelo painel e consumida por vitrines web, aplicações React, PWA, aplicativos móveis e outras interfaces, sem exigir que esses frontends sejam incorporados ao backend.</p>
            <p>O IterStore não se limita a uma loja virtual tradicional: sua proposta é oferecer uma base extensível para diferentes canais de venda e integrações, mantendo no backend os cálculos, as validações e as regras que precisam ser consistentes em todos eles.</p>
        </section>

        <section>
            <h3>Como a plataforma se organiza</h3>
            <ul class="iter-about-features">
                <li>
                    <span class="iter-about-feature-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4M7 8h10M7 12h6"/></svg></span>
                    <div class="iter-about-feature-copy"><strong>Interfaces e canais de venda</strong><span>Vitrines web, PWA, aplicativos móveis e outros frontends independentes que apresentam os produtos e conduzem a experiência de compra.</span></div>
                </li>
                <li>
                    <span class="iter-about-feature-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3"/><rect x="8" y="3" width="8" height="4" rx="1"/><path d="m8 13 2 2 5-5"/></svg></span>
                    <div class="iter-about-feature-copy"><strong>API GraphQL</strong><span>Contrato de comunicação para consultar dados e executar operações comerciais. A autenticação por token permite controlar o acesso às funcionalidades e aos dados autorizados.</span></div>
                </li>
                <li>
                    <span class="iter-about-feature-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 7h16l-1 13H5L4 7Z"/><path d="M9 9V6a3 3 0 0 1 6 0v3M3 7h18"/></svg></span>
                    <div class="iter-about-feature-copy"><strong>Núcleo de comércio</strong><span>Módulos responsáveis por catálogo, clientes, carrinho, pedidos, estoque, frete, pagamentos, promoções e demais regras da operação.</span></div>
                </li>
                <li>
                    <span class="iter-about-feature-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg></span>
                    <div class="iter-about-feature-copy"><strong>Infraestrutura</strong><span>PHP e PostgreSQL como base da aplicação e dos dados, com migrações, configurações, logs e suporte a tarefas agendadas.</span></div>
                </li>
            </ul>
        </section>

        <section>
            <h3>Principais áreas funcionais</h3>
            <ul class="iter-about-features">
                <li>
                    <span class="iter-about-feature-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 8 9-5 9 5-9 5-9-5Z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5"/></svg></span>
                    <div class="iter-about-feature-copy"><strong>Catálogo e EAV</strong><span>Produtos, categorias, atributos dinâmicos, conjuntos de atributos, opções, imagens e variações. O modelo EAV permite criar características comerciais sem adicionar uma coluna física para cada atributo.</span></div>
                </li>
                <li>
                    <span class="iter-about-feature-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 8v6M23 11h-6"/></svg></span>
                    <div class="iter-about-feature-copy"><strong>Clientes e lojas</strong><span>Clientes, endereços, grupos, websites e configurações comerciais, com separação entre a identidade administrativa e a identidade do consumidor.</span></div>
                </li>
                <li>
                    <span class="iter-about-feature-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18M8 10a4 4 0 0 0 8 0"/></svg></span>
                    <div class="iter-about-feature-copy"><strong>Vendas</strong><span>Carrinhos, cálculo de totais, checkout, pedidos, pagamentos, entregas, cancelamentos e atendimento administrativo. Os dados relevantes são registrados no pedido para preservar o histórico mesmo quando o catálogo muda.</span></div>
                </li>
                <li>
                    <span class="iter-about-feature-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 7 12 3l9 4-9 4-9-4Z"/><path d="M3 12l9 4 9-4M3 17l9 4 9-4"/></svg></span>
                    <div class="iter-about-feature-copy"><strong>Estoque e logística</strong><span>Estoque por website, origens, reservas e baixas, tabela própria de frete e integração com transportadoras e serviços de envio, incluindo o Melhor Envio.</span></div>
                </li>
                <li>
                    <span class="iter-about-feature-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 12V8l-8-5-8 5v8l8 5 4-2.5"/><path d="m4 8 8 5 8-5M12 13v8M16 16l2 2 4-4"/></svg></span>
                    <div class="iter-about-feature-copy"><strong>Promoções e cupons</strong><span>Motor de regras comerciais, condições de elegibilidade, descontos e limites de utilização. Os cálculos e a validação devem ocorrer no backend, tanto no carrinho quanto no checkout.</span></div>
                </li>
                <li>
                    <span class="iter-about-feature-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4M16 15h1"/></svg></span>
                    <div class="iter-about-feature-copy"><strong>Integrações e infraestrutura</strong><span>Integrações com serviços como Mercado Pago e Melhor Envio, envio de e-mails, tarefas agendadas, configurações, logs operacionais e ferramentas de manutenção.</span></div>
                </li>
            </ul>
        </section>

        <section>
            <h3>Princípios de arquitetura</h3>
            <ul class="iter-about-features">
                <li><div class="iter-about-feature-copy"><strong>Modularidade</strong><span>As responsabilidades são organizadas em módulos de catálogo, clientes, vendas, estoque, frete e promoções, favorecendo a evolução de áreas específicas.</span></div></li>
                <li><div class="iter-about-feature-copy"><strong>Regras centralizadas</strong><span>O painel, a API e as integrações devem reutilizar os mesmos serviços de negócio, evitando duplicidade de lógica e diferenças de comportamento entre canais.</span></div></li>
                <li><div class="iter-about-feature-copy"><strong>API como contrato</strong><span>O schema GraphQL documenta as operações e os tipos disponíveis para os consumidores da API e deve acompanhar a implementação do backend.</span></div></li>
                <li><div class="iter-about-feature-copy"><strong>Extensibilidade</strong><span>Novos módulos e integrações podem acrescentar recursos por meio de contratos definidos, reduzindo alterações desnecessárias no núcleo.</span></div></li>
                <li><div class="iter-about-feature-copy"><strong>Segurança e rastreabilidade</strong><span>Permissões granulares, autenticação, validações, controle de acesso aos dados e registros operacionais contribuem para uma operação mais segura e auditável.</span></div></li>
            </ul>
        </section>

        <section>
            <h3>Uma implementação pensada para evoluir</h3>
            <p>O IterStore adota uma arquitetura própria, aproveitando conceitos consolidados de plataformas de comércio eletrônico e adaptando-os às necessidades do projeto. A prioridade é manter um núcleo controlado, módulos com responsabilidades claras e pontos de extensão explícitos.</p>
            <p>A camada de dados busca organizar consultas e coleções de forma consistente com o PostgreSQL. O sistema de visualização utiliza layouts declarativos em JSON e componentes identificados por nomes lógicos, permitindo compor, ordenar ou substituir partes da interface sem depender de referências diretas às classes PHP nos layouts.</p>
            <p>O projeto reúne funcionalidades comerciais e especificações de evolução. Por isso, a disponibilidade de cada recurso depende do estado de sua implementação e validação, e não apenas de sua descrição arquitetural.</p>
        </section>

        <footer class="iter-about-signoff">
            <img
                class="iter-about-company-logo"
                src="/assets/admin/egsn-logo.png"
                alt="Logo da EGSN"
                loading="lazy"
            >
            <strong>IterStore</strong>
            <p>Projetado e desenvolvido por <strong>Esmerio Neto</strong>.</p>
            <p>Uma base modular para centralizar o comércio, conectar canais e evoluir com a operação.</p>
            <p>
                <a class="iter-about-contact" href="mailto:esmerioneto@gmail.com">
                    <svg aria-hidden="true" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                        <path d="m3 7 9 6 9-6"></path>
                    </svg>
                    esmerioneto@gmail.com
                </a>
            </p>
            <p class="iter-about-copyright">&copy; <?= date('Y') ?> EGSN. Todos os direitos reservados.</p>
        </footer>
    </div>
</section>
