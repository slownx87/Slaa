<?php
/*
 * Shell visual do app mobile (Vss E Acj - Relógio de Ponto).
 *
 * Aqui fica só a "casca" gerada pelo design (fontes, tema do Tailwind, topbar e
 * barra inferior). As páginas do app incluem este arquivo para não repetir o
 * mesmo tema em três lugares e para o visual continuar exatamente igual em
 * todas as telas.
 */

declare(strict_types=1);

/**
 * Abre o <head> e o <body> com o tema do design.
 *
 * @param string $title    Título da aba.
 * @param string $bodyClass Classes do <body> (cada tela do design tem as suas).
 * @param string $extraCss  CSS específico da tela, já dentro de uma tag <style>.
 * @param string $manifest  Arquivo .webmanifest para a tela virar app instalável.
 */
function app_mobile_head(string $title, string $bodyClass, string $extraCss = '', string $manifest = ''): void
{
    ?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" name="viewport"/>
<meta name="theme-color" content="#050810"/>
<meta name="robots" content="noindex, nofollow"/>
<title><?= h($title) ?></title>
<?php if ($manifest !== ''): ?>
<link href="<?= h($manifest) ?>" rel="manifest"/>
<link href="assets/icones/app-192.png" rel="apple-touch-icon"/>
<meta content="yes" name="apple-mobile-web-app-capable"/>
<meta content="black-translucent" name="apple-mobile-web-app-status-bar-style"/>
<meta content="Ponto ACJ e VSS" name="apple-mobile-web-app-title"/>
<?php endif; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<!-- CSS local. Antes isto era https://cdn.tailwindcss.com carregado em tempo
     de execução: nos celulares onde a CDN não abria (sem dados, wi-fi que
     bloqueia, navegador antigo) o app aparecia sem layout e sem conteúdo.
     Para regerar depois de criar classes novas, veja README-css.md. -->
<link href="assets/tailwind.css?v=1.0.1" rel="stylesheet"/>
<style>
        * { -webkit-tap-highlight-color: transparent; }

        /* Os ícones vêm da fonte "Material Symbols" do Google. Esta regra fica
           aqui (e não só no CSS do Google) por dois motivos:
           1. o tamanho e a ligadura funcionam mesmo se só o arquivo da fonte
              chegar;
           2. se a fonte não carregar — celular sem dados, wi-fi que bloqueia o
              Google — o navegador mostraria a palavra inteira ("calendar_month",
              "check_circle") e isso esticava e quebrava a tela. Com o limite de
              largura a palavra fica presa no espaço de um ícone e o layout se
              mantém. */
        .material-symbols-outlined {
            font-family: "Material Symbols Outlined";
            font-weight: normal;
            font-style: normal;
            line-height: 1;
            letter-spacing: normal;
            text-transform: none;
            display: inline-block;
            white-space: nowrap;
            word-wrap: normal;
            direction: ltr;
            -webkit-font-feature-settings: "liga";
            font-feature-settings: "liga";
            -webkit-font-smoothing: antialiased;
            max-width: 1.2em;
            overflow: hidden;
        }

        body {
            font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif;
            color: #fff;
            background:
                radial-gradient(circle at 15% 5%, rgba(59, 130, 246, .28), transparent 30%),
                radial-gradient(circle at 90% 20%, rgba(0, 174, 239, .18), transparent 28%),
                linear-gradient(135deg, #050810, #0a1322);
            background-attachment: fixed;
        }

        /* Manchas de luz que respiram devagar no fundo, como no app antigo. */
        body::before, body::after {
            content: "";
            position: fixed;
            width: 300px;
            height: 300px;
            border-radius: 999px;
            filter: blur(70px);
            opacity: .30;
            pointer-events: none;
            z-index: -1;
        }
        body::before {
            background: #3b82f6;
            top: -140px; left: -140px;
            animation: flutuar1 14s ease-in-out infinite;
        }
        body::after {
            background: #8b5cf6;
            right: -170px; bottom: 90px;
            animation: flutuar2 18s ease-in-out infinite;
        }
        @keyframes flutuar1 {
            0%, 100% { transform: translate(0, 0) scale(1); opacity: .30; }
            50%      { transform: translate(40px, 60px) scale(1.15); opacity: .42; }
        }
        @keyframes flutuar2 {
            0%, 100% { transform: translate(0, 0) scale(1); opacity: .26; }
            50%      { transform: translate(-50px, -40px) scale(1.12); opacity: .38; }
        }

        .glass-card {
            background: rgba(17, 24, 39, .86);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(148, 163, 184, .18);
        }

        /* ======================= REGRA DE OURO DAS ANIMAÇÕES =======================
           NENHUM conteúdo pode começar invisível esperando uma animação para
           aparecer. Antes era assim: a classe tinha `opacity: 0` e só a animação
           devolvia a opacidade. Nos celulares em que a animação não roda —
           Android com "Remover animações" ligado na acessibilidade, navegador
           antigo que só entende `-webkit-animation`, modo de economia de
           bateria — TODO o conteúdo ficava invisível para sempre. Sobrava só a
           logo e a barra de navegação, que não têm essas classes. Foi
           exatamente a reclamação: "aparece os botões mas não aparece conteúdo".

           O jeito certo, e o único que pode ser usado daqui para frente: o
           elemento é visível por padrão e o estado invisível fica DENTRO do
           @keyframes, no `from`. Animação que não roda = conteúdo na tela, só
           sem o efeito. */
        .fade-in-up {
            animation: fadeInUp .7s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .fade-out-up {
            animation: fadeOutUp .45s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }
        @keyframes fadeOutUp {
            from { opacity: 1; transform: translateY(0); }
            to { opacity: 0; transform: translateY(-20px); }
        }

        /* Entrada em cascata dos itens de lista. Sem `opacity: 0` na classe,
           pelo mesmo motivo do .fade-in-up acima. */
        .surgir {
            animation: surgir .55s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        @keyframes surgir {
            from { opacity: 0; transform: translateY(14px) scale(.98); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* Celular com "Remover animações" / "Reduzir movimento" ligado: o app
           fica parado, mas COMPLETO. A animação termina na hora e para no
           estado final (visível), em vez de nunca começar. */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .01ms !important;
                animation-delay: 0ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .01ms !important;
            }
        }

        /* Brilho pulsante para o botão principal. */
        .glow-pulse { animation: glowPulse 2.6s ease-in-out infinite; }
        @keyframes glowPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, .45), 0 10px 30px rgba(59, 130, 246, .35); }
            50%      { box-shadow: 0 0 0 18px rgba(59, 130, 246, 0), 0 14px 40px rgba(59, 130, 246, .5); }
        }

        /* Faixa de luz que corre sobre as barras de progresso. */
        .barra-viva { position: relative; overflow: hidden; }
        .barra-viva::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.55), transparent);
            transform: translateX(-100%);
            animation: correr 2.4s ease-in-out infinite;
        }
        @keyframes correr { to { transform: translateX(100%); } }

        /* A logo é um PNG transparente e o escudo do ACJ é preto: sem este halo
           claro ele desaparece no fundo escuro do app. */
        .logo-app {
            filter: drop-shadow(0 0 3px rgba(255, 255, 255, .55))
                    drop-shadow(0 2px 6px rgba(0, 0, 0, .55));
        }

        /* ------------------------------ tutorial ------------------------------
           O holofote é um único elemento: a sombra gigante escurece a tela
           inteira e o "buraco" é o próprio elemento, que fica transparente. */
        #tut-raiz {
            position: fixed;
            inset: 0;
            z-index: 200;
            opacity: 0;
            pointer-events: none;
            transition: opacity .25s ease;
        }
        #tut-raiz.aberto { opacity: 1; pointer-events: auto; }

        .tut-fundo { position: fixed; inset: 0; background: rgba(5, 8, 16, .55); }

        .tut-foco {
            position: fixed;
            box-shadow: 0 0 0 9999px rgba(5, 8, 16, .88);
            border: 2px solid #3b82f6;
            pointer-events: none;
            transition: all .35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .tut-anel {
            position: fixed;
            border: 3px solid #60a5fa;
            pointer-events: none;
            animation: tutPulsar 1.6s ease-in-out infinite;
            transition: all .35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes tutPulsar {
            0%, 100% { transform: scale(1);    opacity: .9; }
            50%      { transform: scale(1.08); opacity: .25; }
        }

        .tut-seta {
            position: fixed;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #60a5fa;
            pointer-events: none;
            animation: tutSaltar 1.1s ease-in-out infinite;
            filter: drop-shadow(0 2px 6px rgba(0, 0, 0, .6));
        }
        .tut-seta span { font-size: 40px; font-variation-settings: 'FILL' 1; }
        @keyframes tutSaltar {
            0%, 100% { transform: translateY(0); }
            50%      { transform: translateY(7px); }
        }

        .tut-balao {
            position: fixed;
            left: 16px;
            right: 16px;
            max-width: 400px;
            margin: 0 auto;
            background: #111827;
            border: 1px solid rgba(96, 165, 250, .45);
            border-radius: 18px;
            padding: 16px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, .6);
            transition: top .35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .tut-balao-topo { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
        .tut-contador { font-size: 10px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #60a5fa; }
        .tut-pular { font-size: 11px; font-weight: 600; color: #94a3b8; background: none; border: 0; padding: 4px; cursor: pointer; }
        .tut-titulo { font-size: 17px; font-weight: 700; line-height: 22px; color: #fff; margin: 0 0 6px; }
        .tut-texto { font-size: 13px; line-height: 19px; color: #cbd5e1; margin: 0 0 12px; }
        .tut-texto b { color: #fff; }

        .tut-pontos { display: flex; gap: 5px; justify-content: center; margin-bottom: 12px; }
        .tut-pontos span { width: 6px; height: 6px; border-radius: 999px; background: rgba(148, 163, 184, .35); transition: all .25s ease; }
        .tut-pontos span.ativo { background: #3b82f6; width: 16px; }

        .tut-botoes { display: flex; gap: 8px; }
        .tut-botoes button {
            flex: 1;
            height: 42px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: transform .12s ease;
        }
        .tut-botoes button:active { transform: scale(.96); }
        .tut-voltar { background: transparent; border: 1px solid #64748b; color: #fff; }
        .tut-proximo { background: #3b82f6; border: 0; color: #fff; }

        .pb-safe { padding-bottom: env(safe-area-inset-bottom, 0px); }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition-duration: .01ms !important; }
        }

        /* Aviso flutuante (erros e confirmações), no mesmo estilo das telas. */
        #app-toast {
            position: fixed;
            left: 50%;
            bottom: 88px;
            transform: translate(-50%, 24px);
            z-index: 90;
            max-width: calc(100% - 40px);
            opacity: 0;
            pointer-events: none;
            transition: opacity .25s ease, transform .25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        #app-toast.show { opacity: 1; transform: translate(-50%, 0); }
    </style>
<?= $extraCss ?>
</head>
<body class="<?= h($bodyClass) ?>">
<div aria-live="polite" class="hidden rounded-full px-5 py-3 shadow-lg font-body-md text-body-md text-center" id="app-toast" role="status"></div>
    <?php
}

/**
 * Barra superior: só a logo, centralizada, sem moldura redonda e sem o nome
 * escrito ao lado. $voltar preenchido acrescenta a seta de voltar à esquerda.
 */
function app_mobile_topbar(string $voltar = '', bool $fixed = true): void
{
    $pos = $fixed ? 'fixed top-0' : 'relative';
    ?>
<header class="<?= $pos ?> w-full z-50 backdrop-blur-xl border-b border-white/10 bg-surface/70 transition-all duration-200">
<div class="relative flex items-center justify-center px-container-margin h-14 w-full max-w-md mx-auto">
<?php if ($voltar !== ''): ?>
<a class="absolute left-container-margin w-10 h-10 flex items-center justify-center rounded-full text-primary hover:bg-primary/10 transition-all active:scale-95" href="<?= h($voltar) ?>" aria-label="Voltar">
<span class="material-symbols-outlined">arrow_back</span>
</a>
<?php endif; ?>
<a class="flex items-center justify-center active:scale-95 transition-transform" href="bater-ponto.php" aria-label="<?= h(APP_NAME) ?>">
<img alt="<?= h(APP_NAME) ?>" class="logo-app h-9 w-auto object-contain" src="<?= h(LOGO_PATH) ?>"/>
</a>
</div>
</header>
    <?php
}

/**
 * Logo usada no topo das telas de PIN, que não têm a barra superior.
 */
function app_mobile_logo(string $classes = 'logo-app h-11 w-auto object-contain'): void
{
    ?><img alt="<?= h(APP_NAME) ?>" class="<?= h($classes) ?>" src="<?= h(LOGO_PATH) ?>"/><?php
}

/**
 * Barra inferior do design. $ativo: home | historico | pedidos | perfil.
 */
function app_mobile_nav(string $ativo): void
{
    $itens = [
        'home'      => ['bater-ponto.php',       'dashboard',     'Início'],
        'historico' => ['historico.php',         'history',       'Histórico'],
        'pedidos'   => ['solicitar-ajuste.php',  'edit_calendar', 'Pedidos'],
        'perfil'    => ['bater-ponto.php#perfil','person',        'Perfil'],
    ];
    ?>
<nav class="fixed bottom-0 w-full z-50 rounded-t-2xl backdrop-blur-2xl border-t border-white/20 shadow-lg bg-surface/80" aria-label="Navegação principal">
<div class="flex justify-around items-center px-container-margin pb-safe h-16 max-w-md mx-auto">
<?php foreach ($itens as $chave => [$href, $icone, $rotulo]):
    $on = ($chave === $ativo); ?>
<a class="flex flex-col items-center justify-center <?= $on ? 'text-primary bg-primary/10 rounded-xl px-4 py-1' : 'text-secondary hover:text-primary' ?> transition-transform duration-300 active:scale-90 w-16" href="<?= h($href) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
<span class="material-symbols-outlined mb-1" style="font-variation-settings: 'FILL' <?= $on ? '1' : '0' ?>;"><?= h($icone) ?></span>
<span class="font-label-bold text-label-bold"><?= h($rotulo) ?></span>
</a>
<?php endforeach; ?>
</div>
</nav>
    <?php
}

/**
 * Publica os utilitários de JS usados por todas as telas (aviso flutuante e
 * chamada ao backend com token CSRF). Chame antes do script da própria tela.
 */
function app_mobile_footer(string $csrf): void
{
    ?>
<script>
    window.PontoApp = (function () {
        const csrf = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;
        const toastEl = document.getElementById('app-toast');
        let toastTimer = null;

        function toast(mensagem, tipo) {
            if (!toastEl) return;
            const erro = tipo === 'error';
            toastEl.className = 'fixed rounded-full px-5 py-3 shadow-lg font-body-md text-body-md text-center ' +
                (erro ? 'bg-error text-on-error' : 'bg-inverse-surface text-inverse-on-surface');
            toastEl.textContent = mensagem;
            toastEl.classList.add('show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => toastEl.classList.remove('show'), 4200);
        }

        async function post(dados) {
            const corpo = new URLSearchParams(Object.assign({ ajax: '1', csrf_token: csrf }, dados));
            const resposta = await fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: corpo
            });
            let json = null;
            try { json = await resposta.json(); } catch (e) { json = null; }
            if (!resposta.ok || !json || json.ok === false) {
                throw new Error(json && json.error ? json.error : 'Não foi possível concluir. Tente novamente.');
            }
            return json;
        }

        function vibrar(ms) {
            if (navigator.vibrate) { try { navigator.vibrate(ms); } catch (e) {} }
        }

        /* ------------------------------- app instalável -------------------------------
           O service worker deixa o app abrir rápido e mostrar aviso decente sem
           internet. Só funciona em HTTPS (ou localhost). */
        let promptInstalacao = null;

        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('sw.js').catch(function () {
                    /* Sem HTTPS o navegador recusa: o site segue funcionando igual. */
                });
            });
        }

        window.addEventListener('beforeinstallprompt', function (evento) {
            evento.preventDefault();
            promptInstalacao = evento;
            document.querySelectorAll('[data-instalar-app]').forEach(function (el) {
                el.classList.remove('hidden');
                el.classList.add('flex');
            });
        });

        window.addEventListener('appinstalled', function () {
            promptInstalacao = null;
            document.querySelectorAll('[data-instalar-app]').forEach(function (el) {
                el.classList.add('hidden');
            });
            toast('App instalado! Procure o ícone na tela do celular.');
        });

        async function instalarApp() {
            /* iPhone não tem instalação automática: só dá para ensinar o caminho. */
            const ehIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
            if (!promptInstalacao) {
                toast(ehIOS
                    ? 'No iPhone: toque em Compartilhar e depois em "Adicionar à Tela de Início".'
                    : 'Use o menu do navegador e escolha "Instalar aplicativo" ou "Adicionar à tela inicial".');
                return;
            }
            promptInstalacao.prompt();
            await promptInstalacao.userChoice;
            promptInstalacao = null;
        }

        document.addEventListener('click', function (evento) {
            if (evento.target.closest('[data-instalar-app]')) instalarApp();
        });

        return { toast, post, vibrar, csrf, instalarApp };
    })();
</script>
    <?php
}

/** Fecha a página. Chame depois do script da tela. */
function app_mobile_end(): void
{
    ?>
</body>
</html>
    <?php
}
