# View Component JSON — Especificação de Implementação

## 1. Objetivo

Criar um sistema de View inspirado no Magento 2, porém sem XML.

A configuração de layout será feita exclusivamente em JSON, mantendo as principais capacidades do sistema de layout:

- composição hierárquica;
- componentes de visualização;
- ordenação;
- `before` / `after`;
- remoção de componentes;
- configuração de dados;
- sobrescrita de template;
- merge de layouts;
- reutilização entre módulos.

A principal simplificação é **não expor o conceito de `Block` no layout**.

O JSON trabalha com **View Components**.

---

# 2. Conceito principal

A estrutura será:

```text
JSON
  ↓
Layout
  ↓
Component Tree
  ↓
Component Resolver
  ↓
View Component
  ↓
Template / Renderer
  ↓
HTML
```

O JSON descreve a estrutura da página.

A implementação PHP/engine é responsável por resolver classes, templates e renderização.

---

# 3. View Component

Um componente é a unidade básica do layout.

Exemplo:

```json
{
  "content": {
    "product": "catalog/product",
    "price": "catalog/price"
  }
}
```

Neste caso:

```text
content
├── product → catalog/product
└── price   → catalog/price
```

`catalog/product` é um identificador lógico do componente.

O JSON não precisa conhecer:

- namespace PHP;
- nome da classe;
- caminho físico do template;
- detalhes internos de renderização.

---

# 4. Forma simplificada

A forma preferencial para componentes simples é:

```json
{
  "header": {
    "logo": "store/logo",
    "menu": "store/menu"
  },

  "content": {
    "product": "catalog/product",
    "price": "catalog/price",
    "related": "catalog/related"
  },

  "footer": {
    "links": "store/links"
  }
}
```

Regra:

```text
"nome": "componente"
```

significa:

> Criar um componente chamado `nome` usando o identificador `componente`.

---

# 5. Configuração avançada

Quando um componente precisa de configuração adicional, utilizar um objeto:

```json
{
  "content": {
    "discount": {
      "component": "catalog/discount",
      "data": {
        "label": "Promoção"
      }
    }
  }
}
```

A propriedade `component` identifica o componente.

---

# 6. Propriedades suportadas

A configuração avançada poderá utilizar:

```json
{
  "component": "catalog/discount",
  "template": "custom/discount",
  "before": "price",
  "after": "product",
  "data": {
    "label": "Promoção"
  }
}
```

## `component`

Identifica o componente que será resolvido pelo framework.

```json
"component": "catalog/discount"
```

Obrigatório quando a configuração é feita como objeto e o componente ainda não possui uma definição anterior.

---

## `template`

Permite sobrescrever o template padrão do componente.

```json
{
  "component": "catalog/product",
  "template": "custom/product"
}
```

O template deve ser uma exceção, não a configuração normal.

O componente deve possuir um template padrão definido pelo próprio framework/módulo.

---

## `data`

Dados adicionais enviados ao componente.

```json
{
  "component": "catalog/discount",
  "data": {
    "label": "Promoção",
    "showPercentage": true
  }
}
```

Esses dados devem ficar disponíveis para o componente durante a renderização.

---

# 7. Ordenação

O sistema deve suportar:

```json
{
  "content": {
    "product": "catalog/product",

    "price": "catalog/price",

    "discount": {
      "component": "catalog/discount",
      "after": "price"
    }
  }
}
```

Resultado:

```text
product
price
discount
```

Para colocar antes:

```json
{
  "content": {
    "discount": {
      "component": "catalog/discount",
      "before": "price"
    }
  }
}
```

Resultado:

```text
product
discount
price
```

---

# 8. Posições especiais

Suportar:

```json
"before": "@first"
```

e:

```json
"after": "@last"
```

Exemplo:

```json
{
  "content": {
    "banner": {
      "component": "marketing/banner",
      "before": "@first"
    },

    "footer-info": {
      "component": "store/footer-info",
      "after": "@last"
    }
  }
}
```

---

# 9. Remoção

Para remover um componente existente:

```json
{
  "content": {
    "price": false
  }
}
```

O engine deve localizar `content.price` e removê-lo da árvore final.

Isso permite que módulos alterem layouts existentes sem precisar conhecer a implementação interna do componente.

---

# 10. Hierarquia

A própria estrutura JSON define o relacionamento entre os componentes.

Exemplo:

```json
{
  "content": {
    "product": {
      "info": {
        "title": "catalog/product-title",
        "price": "catalog/price"
      }
    }
  }
}
```

Árvore:

```text
content
└── product
    └── info
        ├── title
        └── price
```

Não é necessário declarar explicitamente `parent`.

---

# 11. Componentes Container

Um componente pode funcionar apenas como container.

Exemplo:

```json
{
  "content": {
    "product-info": {
      "component": "container",
      "children": {
        "title": "catalog/product-title",
        "price": "catalog/price"
      }
    }
  }
}
```

Entretanto, sempre que possível, a recomendação é utilizar a própria hierarquia JSON para evitar configurações desnecessárias.

Uma implementação ainda mais simples pode permitir:

```json
{
  "content": {
    "product-info": {
      "title": "catalog/product-title",
      "price": "catalog/price"
    }
  }
}
```

Neste caso, `product-info` pode ser tratado automaticamente como um nó/container quando não possuir `component`.

---

# 12. Regra recomendada para interpretação do JSON

O parser deve seguir estas regras:

### String

```json
"product": "catalog/product"
```

Cria um componente.

### Objeto com `component`

```json
"product": {
  "component": "catalog/product"
}
```

Cria um componente configurado.

### Objeto sem `component`

```json
"product": {
  "price": "catalog/price",
  "stock": "catalog/stock"
}
```

Cria um container/nó hierárquico.

### Boolean `false`

```json
"price": false
```

Remove o componente.

---

# 13. Resolver

O framework deve possuir um `ComponentResolver`.

Responsabilidade:

```text
catalog/product
      ↓
ComponentResolver
      ↓
Catalog\View\Product
```

Exemplo conceitual:

```php
$component = $resolver->resolve('catalog/product');
```

O resolver pode utilizar um registry/configuração:

```php
return [
    'catalog/product' => Catalog\Product\View::class,
    'catalog/price'   => Catalog\Price\View::class,
];
```

O JSON não precisa conhecer essas classes.

---

# 14. Component Tree

O JSON deve ser convertido para uma árvore interna.

Exemplo:

```json
{
  "content": {
    "product": "catalog/product",
    "price": "catalog/price",
    "discount": {
      "component": "catalog/discount",
      "after": "price"
    }
  }
}
```

Árvore:

```text
content
├── product
├── price
└── discount
```

Cada nó deve possuir, conceitualmente:

```php
ComponentNode
```

com informações como:

```php
$name
$component
$parent
$children
$data
$template
$before
$after
```

---

# 15. Layout Merge

Cada módulo poderá fornecer seu próprio JSON.

Exemplo:

```text
Module_Catalog
└── view/frontend/layout/product.json

Module_Discount
└── view/frontend/layout/product.json
```

O framework deve carregar todos os layouts aplicáveis e fazer o merge.

Exemplo base:

```json
{
  "content": {
    "product": "catalog/product",
    "price": "catalog/price"
  }
}
```

Módulo de desconto:

```json
{
  "content": {
    "discount": {
      "component": "catalog/discount",
      "after": "price"
    }
  }
}
```

Resultado:

```text
content
├── product
├── price
└── discount
```

---

# 16. Ordem de processamento

O processamento recomendado é:

```text
1. Descobrir layouts
2. Carregar JSON
3. Fazer merge
4. Criar Component Tree
5. Aplicar remoções
6. Resolver componentes
7. Resolver before/after
8. Validar árvore
9. Renderizar
```

A resolução de `before`/`after` deve acontecer depois do merge.

Isso é importante porque o componente referenciado pode estar sendo adicionado por outro módulo.

---

# 17. Regras para before/after

Exemplo:

```json
{
  "content": {
    "a": "component/a",

    "c": {
      "component": "component/c",
      "after": "a"
    },

    "b": {
      "component": "component/b",
      "before": "c"
    }
  }
}
```

Resultado:

```text
a
b
c
```

## Componente inexistente

Se:

```json
"after": "unknown"
```

e `unknown` não existir, o comportamento padrão recomendado é:

```text
adicionar no final do container
```

Opcionalmente, o framework poderá possuir um modo estrito que lança exceção.

---

# 18. Dependências circulares

O engine deve detectar situações como:

```text
A after B
B after A
```

ou:

```text
A before B
B before C
C before A
```

Nesse caso deve gerar erro de configuração.

Nunca permitir loop infinito durante a ordenação.

---

# 19. Identificação dos componentes

Dentro de um mesmo parent, o nome deve ser único.

Válido:

```json
{
  "content": {
    "product": "catalog/product",
    "price": "catalog/price"
  }
}
```

Inválido:

```text
content
├── price
└── price
```

Para componentes com o mesmo tipo, utilizar nomes diferentes:

```json
{
  "sidebar": {
    "product-price": "catalog/price",
    "compare-price": "catalog/price"
  }
}
```

---

# 20. Move

A funcionalidade `move` pode ser implementada posteriormente.

Ela será necessária quando um componente existente precisar mudar de parent.

Exemplo futuro:

```json
{
  "moves": {
    "price": {
      "to": "sidebar",
      "after": "product"
    }
  }
}
```

Não é necessário implementar `move` na primeira versão se o objetivo for manter o núcleo simples.

---

# 21. API interna sugerida

A implementação pode ser dividida em:

```text
LayoutLoader
LayoutMerger
LayoutParser
ComponentTree
ComponentNode
ComponentResolver
ComponentRegistry
PositionResolver
LayoutRenderer
```

Responsabilidades:

### LayoutLoader

Localiza e carrega arquivos JSON.

### LayoutMerger

Combina layouts provenientes de diferentes módulos.

### LayoutParser

Converte JSON em `ComponentTree`.

### ComponentTree

Mantém a árvore de componentes.

### ComponentNode

Representa um componente individual.

### ComponentRegistry

Mapeia identificadores para componentes.

### ComponentResolver

Cria/resolve o componente.

### PositionResolver

Processa:

- `before`;
- `after`;
- `@first`;
- `@last`.

### LayoutRenderer

Percorre a árvore e gera a saída final.

---

# 22. Estrutura de diretórios sugerida

Exemplo:

```text
Module_Catalog/
├── etc/
│   └── components.php
│
└── view/
    └── frontend/
        ├── layout/
        │   └── product.json
        │
        ├── templates/
        │   └── product.php
        │
        └── components/
            └── Product.php
```

Registro:

```php
return [
    'catalog/product' => Catalog\View\Product::class,
];
```

Layout:

```json
{
  "content": {
    "product": "catalog/product"
  }
}
```

---

# 23. Exemplo completo

Layout principal:

```json
{
  "page": {
    "header": {
      "logo": "store/logo",
      "menu": "store/menu"
    },

    "content": {
      "product": "catalog/product",
      "price": "catalog/price",

      "discount": {
        "component": "catalog/discount",
        "after": "price",
        "data": {
          "label": "Oferta"
        }
      },

      "related": {
        "component": "catalog/related",
        "after": "@last"
      }
    },

    "footer": {
      "links": "store/links"
    }
  }
}
```

Resultado:

```text
page
├── header
│   ├── logo
│   └── menu
│
├── content
│   ├── product
│   ├── price
│   ├── discount
│   └── related
│
└── footer
    └── links
```

---

# 24. Princípio arquitetural

O JSON deve ser **declarativo**.

Evitar:

```json
{
  "class": "Vendor\\Module\\Block\\Product",
  "template": "catalog/product.phtml",
  "type": "block"
}
```

Preferir:

```json
{
  "product": "catalog/product"
}
```

O framework conhece os detalhes de implementação.

O desenvolvedor do layout trabalha com componentes.

---

# 25. Comparação com Magento XML

Magento tradicional:

```xml
<block
    class="Catalog\\Block\\Product"
    name="product"
    template="product.phtml"
    after="price">
</block>
```

Novo sistema:

```json
{
  "product": {
    "component": "catalog/product",
    "after": "price"
  }
}
```

Ou, quando não houver configuração adicional:

```json
{
  "product": "catalog/product"
}
```

A segunda forma deve ser considerada a forma padrão.

---

# 26. Objetivo da primeira versão

A primeira versão do framework deve implementar somente:

```text
✓ Component Tree
✓ Component Registry
✓ Component Resolver
✓ JSON Loader
✓ JSON Merge
✓ Component nesting
✓ before
✓ after
✓ @first
✓ @last
✓ remove
✓ data
✓ template override
✓ renderização
```

Deixar para versões posteriores:

```text
○ move
○ aliases
○ conditional components
○ eventos
○ cache por componente
○ lazy rendering
○ componentes assíncronos
○ dependências avançadas
```

---

# 27. Sintaxe oficial recomendada

A sintaxe principal do framework deve ser:

```json
{
  "content": {
    "product": "catalog/product",
    "price": "catalog/price",

    "discount": {
      "component": "catalog/discount",
      "after": "price",
      "data": {
        "label": "Promoção"
      }
    }
  }
}
```

Com apenas quatro conceitos fundamentais:

```text
STRING  → componente
OBJECT  → container ou componente configurado
FALSE   → remoção
NESTING → hierarquia
```

E três propriedades de posicionamento:

```text
before
after
@first / @last
```

---

# 28. Decisão de design

**Não reproduzir o Magento XML em JSON.**

O objetivo não é transformar:

```xml
<block ...>
```

em:

```json
{
  "block": {
    ...
  }
}
```

O objetivo é criar uma abstração mais simples:

```text
Layout
  ↓
View Components
  ↓
Component Tree
  ↓
Renderer
```

O `Block` pode continuar existindo internamente, caso seja útil para compatibilidade ou implementação, mas **não deve ser uma preocupação do autor do layout JSON**.

O layout deve expressar intenção:

```json
"price": "catalog/price"
```

e não detalhes de implementação:

```json
"class": "...",
"template": "..."
```

Essa separação permite que o framework evolua futuramente sem precisar alterar os layouts existentes.
