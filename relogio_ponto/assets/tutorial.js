/*
 * Tutorial guiado do app.
 *
 * Escurece a tela toda, abre um "buraco" de luz em cima do botão que está
 * sendo explicado, aponta uma seta piscando e mostra um balão com o passo a
 * passo. Funciona com os elementos reais da página — não é imagem.
 *
 * Uso:
 *   Tutorial.iniciar(PASSOS, { chave: 'inicio', proxima: {...} });
 *
 * Cada passo:
 *   {
 *     alvo:   '#idDoBotao'      // opcional; sem alvo o passo vira tela cheia
 *     forma:  'circulo'|'caixa' // formato do buraco de luz (padrão: caixa)
 *     titulo: 'Bater ponto',
 *     texto:  'Explicação bem mastigada.',
 *     antes:  function () {}    // opcional: prepara a tela antes do passo
 *   }
 */
window.Tutorial = (function () {
    'use strict';

    var passos = [];
    var indice = 0;
    var opcoes = {};
    var elementos = null;
    var aoRedimensionar = null;

    /* --------------------------- montagem da tela --------------------------- */
    function montar() {
        if (elementos) return elementos;

        var raiz = document.createElement('div');
        raiz.id = 'tut-raiz';
        raiz.innerHTML =
            '<div class="tut-fundo"></div>' +
            '<div class="tut-foco"></div>' +
            '<div class="tut-anel"></div>' +
            '<div class="tut-seta"><span class="material-symbols-outlined">arrow_downward</span></div>' +
            '<div class="tut-balao">' +
                '<div class="tut-balao-topo">' +
                    '<span class="tut-contador"></span>' +
                    '<button class="tut-pular" type="button">Pular</button>' +
                '</div>' +
                '<h3 class="tut-titulo"></h3>' +
                '<p class="tut-texto"></p>' +
                '<div class="tut-pontos"></div>' +
                '<div class="tut-botoes">' +
                    '<button class="tut-voltar" type="button">Voltar</button>' +
                    '<button class="tut-proximo" type="button">Próximo</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(raiz);

        elementos = {
            raiz: raiz,
            fundo: raiz.querySelector('.tut-fundo'),
            foco: raiz.querySelector('.tut-foco'),
            anel: raiz.querySelector('.tut-anel'),
            seta: raiz.querySelector('.tut-seta'),
            balao: raiz.querySelector('.tut-balao'),
            contador: raiz.querySelector('.tut-contador'),
            titulo: raiz.querySelector('.tut-titulo'),
            texto: raiz.querySelector('.tut-texto'),
            pontos: raiz.querySelector('.tut-pontos'),
            voltar: raiz.querySelector('.tut-voltar'),
            proximo: raiz.querySelector('.tut-proximo'),
            pular: raiz.querySelector('.tut-pular')
        };

        elementos.voltar.addEventListener('click', function () { ir(indice - 1); });
        elementos.proximo.addEventListener('click', function () { ir(indice + 1); });
        elementos.pular.addEventListener('click', function () { encerrar(false); });

        return elementos;
    }

    /* ------------------------------ posicionamento ------------------------------ */
    function posicionar(passo) {
        var el = elementos;
        var alvo = passo.alvo ? document.querySelector(passo.alvo) : null;

        /* Passo sem alvo (ou com alvo escondido): balão no meio, sem holofote. */
        if (!alvo || !alvo.getBoundingClientRect || alvo.getBoundingClientRect().width === 0) {
            el.foco.style.display = 'none';
            el.anel.style.display = 'none';
            el.seta.style.display = 'none';
            el.balao.style.top = '50%';
            el.balao.style.transform = 'translateY(-50%)';
            return;
        }

        var r = alvo.getBoundingClientRect();
        var folga = 8;
        var raio = passo.forma === 'circulo' ? '999px' : '16px';

        el.foco.style.display = 'block';
        el.foco.style.top = (r.top - folga) + 'px';
        el.foco.style.left = (r.left - folga) + 'px';
        el.foco.style.width = (r.width + folga * 2) + 'px';
        el.foco.style.height = (r.height + folga * 2) + 'px';
        el.foco.style.borderRadius = raio;

        el.anel.style.display = 'block';
        el.anel.style.top = el.foco.style.top;
        el.anel.style.left = el.foco.style.left;
        el.anel.style.width = el.foco.style.width;
        el.anel.style.height = el.foco.style.height;
        el.anel.style.borderRadius = raio;

        /* Balão vai para o lado com mais espaço; a seta aponta para o alvo. */
        var alturaBalao = el.balao.offsetHeight || 200;
        var espacoAbaixo = window.innerHeight - r.bottom;
        var embaixo = espacoAbaixo > alturaBalao + 90;

        el.balao.style.transform = 'none';
        el.seta.style.display = 'block';
        el.seta.style.left = Math.max(16, Math.min(window.innerWidth - 56, r.left + r.width / 2 - 20)) + 'px';

        if (embaixo) {
            el.balao.style.top = (r.bottom + 64) + 'px';
            el.seta.style.top = (r.bottom + 12) + 'px';
            el.seta.querySelector('span').textContent = 'arrow_upward';
        } else {
            el.balao.style.top = Math.max(12, r.top - alturaBalao - 64) + 'px';
            el.seta.style.top = Math.max(6, r.top - 52) + 'px';
            el.seta.querySelector('span').textContent = 'arrow_downward';
        }
    }

    /* -------------------------------- navegação -------------------------------- */
    function ir(novo) {
        if (novo < 0) return;
        if (novo >= passos.length) { encerrar(true); return; }

        indice = novo;
        var passo = passos[indice];
        var el = elementos;

        if (typeof passo.antes === 'function') {
            try { passo.antes(); } catch (e) { /* passo opcional não pode travar o tutorial */ }
        }

        el.titulo.textContent = passo.titulo || '';
        el.texto.innerHTML = passo.texto || '';
        el.contador.textContent = 'Passo ' + (indice + 1) + ' de ' + passos.length;
        el.voltar.style.visibility = indice === 0 ? 'hidden' : 'visible';
        el.proximo.textContent = indice === passos.length - 1 ? 'Terminei' : 'Próximo';

        var bolinhas = '';
        for (var i = 0; i < passos.length; i++) {
            bolinhas += '<span class="' + (i === indice ? 'ativo' : '') + '"></span>';
        }
        el.pontos.innerHTML = bolinhas;

        var alvo = passo.alvo ? document.querySelector(passo.alvo) : null;
        if (alvo && alvo.scrollIntoView) {
            alvo.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        /* Espera a rolagem parar para medir a posição certa. */
        setTimeout(function () { posicionar(passo); }, alvo ? 320 : 0);
    }

    function encerrar(completou) {
        if (!elementos) return;
        elementos.raiz.classList.remove('aberto');
        window.removeEventListener('resize', aoRedimensionar);

        setTimeout(function () {
            if (elementos && elementos.raiz.parentNode) {
                elementos.raiz.parentNode.removeChild(elementos.raiz);
            }
            elementos = null;
        }, 250);

        if (opcoes.chave) {
            try { localStorage.setItem('tutorial_visto_' + opcoes.chave, '1'); } catch (e) {}
        }

        /* Terminou tudo e existe uma próxima tela para explicar? Oferece. */
        if (completou && opcoes.proxima && window.confirm(opcoes.proxima.pergunta)) {
            window.location.href = opcoes.proxima.url;
        }
    }

    /* --------------------------------- público --------------------------------- */
    function iniciar(lista, config) {
        passos = (lista || []).filter(function (p) {
            /* Some com passos cujo alvo não existe nesta tela. */
            return !p.alvo || document.querySelector(p.alvo);
        });
        if (!passos.length) return;

        opcoes = config || {};
        montar();
        requestAnimationFrame(function () { elementos.raiz.classList.add('aberto'); });

        aoRedimensionar = function () { posicionar(passos[indice]); };
        window.addEventListener('resize', aoRedimensionar);

        ir(0);
    }

    /** Mostra sozinho só na primeira vez que a pessoa abre aquela tela. */
    function iniciarSePrimeiraVez(lista, config) {
        var chave = (config || {}).chave;
        if (!chave) return;
        try {
            if (localStorage.getItem('tutorial_visto_' + chave) === '1') return;
        } catch (e) { return; }
        setTimeout(function () { iniciar(lista, config); }, 900);
    }

    return { iniciar: iniciar, iniciarSePrimeiraVez: iniciarSePrimeiraVez };
})();
