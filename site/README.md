# Calculadora de Preço — site

Site de precificação para pequenos negócios, construído a partir do
levantamento de fórmulas das planilhas originais
(`docs/LEVANTAMENTO_FUNCOES_PLANILHAS.md`).

## Como abrir

Não precisa instalar nada. Duas opções:

1. **Abrir direto**: dê duplo clique em `site/index.html`.
2. **Servir localmente** (recomendado, evita qualquer restrição de navegador
   com arquivos locais): na pasta `site/`, rode `python3 -m http.server 8000`
   e abra `http://localhost:8000/index.html`.

Funciona em qualquer hospedagem de arquivo estático (GitHub Pages, Netlify,
etc.) sem nenhuma configuração — é só HTML, CSS e JavaScript puros.

## Páginas

| Arquivo | O que faz |
|---|---|
| `index.html` | Página inicial — explica o site e leva pras ferramentas |
| `custos.html` | Cadastro de custos fixos e variáveis do negócio |
| `materiais.html` | Cadastro de materiais/insumos |
| `produtos.html` | Precificação por ficha técnica ou custo direto |
| `lote.html` | Precificação rápida de vários produtos de uma vez |
| `limites.html` | Simulador: até onde cada indicador pode subir sem passar de um preço-limite |
| `servicos.html` | Preço da hora de trabalho a partir do salário desejado |
| `cursos.html` | Precificação de curso online (por clique pago ou por audiência) |

## Como é organizado

```
site/
├── index.html, custos.html, ... (uma página por ferramenta)
├── shared/
│   ├── engine.js    — motor de cálculo (puro, sem DOM) — ver abaixo
│   ├── storage.js   — estado salvo em localStorage (chave "calculadora_preco_v1")
│   ├── nav.js        — barra de navegação, igual em todas as páginas
│   └── style.css     — design compartilhado (cores, tipografia, componentes)
```

Cada página é um arquivo HTML independente (sem `<!doctype>`/`<html>`/`<head>`/
`<body>` — o navegador completa isso sozinho) que carrega os 4 arquivos de
`shared/` via `<link>`/`<script src>`. Não há build, bundler nem dependências
de npm — abrir o arquivo já funciona.

### O motor de cálculo (`shared/engine.js`)

Todas as telas de precificação chamam a mesma fórmula central:

```
markup = 1 / (1 - (% custo fixo + % custo variável + % margem [+ taxas do módulo]))
preço  = custo × markup
```

Cada função de `engine.js` foi conferida, número por número, contra os
valores reais das planilhas originais (pasta de planilhas "Preço de Venda
Completo"). Convenção única em toda a API: todo parâmetro de percentual é
passado como o usuário digitaria (ex. `10` para 10%) — cada função divide
por 100 internamente; o que uma função *devolve* como percentual vem em
fração (`0.10`), para exibir com `Engine.pct(fracao)`.

### Dados do usuário

Tudo fica em `localStorage`, só no navegador de quem está usando — nada é
enviado para servidor nenhum. A mesma chave (`calculadora_preco_v1`) é usada
pela v1 do app (`app/index.html`), então dados cadastrados ali continuam
disponíveis aqui.
