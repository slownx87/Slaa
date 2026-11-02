# O CSS do app (leia antes de mexer no visual)

## O problema que isso resolve

Alguns funcionários abriam o app e viam **só os botões de navegação, sem
conteúdo nas páginas**. O motivo: o app carregava o Tailwind de
`https://cdn.tailwindcss.com` toda vez que abria. Quando esse endereço não
respondia — celular sem dados, wi-fi do condomínio que bloqueia, navegador
antigo, operadora com filtro — a página vinha **sem nenhum estilo**: a logo
ocupava a tela inteira, o conteúdo era empurrado para fora e os painéis que
deviam ficar escondidos apareciam.

Agora o CSS é **um arquivo do próprio servidor**: `assets/tailwind.css`. O app
não depende mais de internet externa para ter layout.

## O preço disso

O arquivo é **compilado**, não é o Tailwind inteiro. Ele só tem as classes que
já existem nas páginas hoje. Ou seja:

> **Se você escrever uma classe nova do Tailwind (`class="mt-9"`, `bg-error/40`,
> `grid-cols-5`...), ela não vai funcionar até regerar o CSS.**

## Como regerar

Na pasta do projeto, com internet:

```bash
python ferramentas/gerar-css.py
```

O script:

1. varre todos os `.php` e `.js` procurando classes;
2. abre uma página local no navegador;
3. essa página usa a CDN do Tailwind **uma vez** para compilar tudo;
4. grava o resultado em `assets/tailwind.css`.

Quando aparecer **"Pronto"** na página, feche a aba e pare o script (Ctrl+C).
Depois suba `assets/tailwind.css` para a Hostinger.

## Mudar cor, tamanho ou espaçamento

O tema (paleta escura, tamanhos reduzidos ~20%, fontes) fica em
**`assets/tailwind-config.js`**. Esse arquivo **não é carregado pelo app** —
serve só para a compilação. Mudou algo lá? Rode `ferramentas/gerar-css.py` de
novo.

## Depois de subir uma versão nova

O app é instalável (PWA) e guarda os arquivos fixos em cache. Para o celular
pegar o CSS novo:

- suba o `assets/tailwind.css`;
- aumente o número em `<link href="assets/tailwind.css?v=1.0.0">` no
  `_app_shell.php` (v=1.0.1, v=1.0.2...);
- aumente também `const CACHE = 'ponto-vN'` no `sw.js`.

Sem isso o celular pode continuar usando a versão guardada.

## Ícones

Os ícones ainda vêm do Google (`Material Symbols`). Se essa fonte não carregar,
o navegador mostraria a palavra da ligadura ("calendar_month", "check_circle")
no lugar do desenho. Para isso não estourar a tela, o `_app_shell.php` limita a
largura da classe `.material-symbols-outlined` ao tamanho de um ícone. O app
fica sem os desenhos, mas **continua utilizável e no lugar** — que é o que
importava.
