/* =========================================================================
   VN System — barra de pesquisa nos seletores de cliente
   Em qualquer <select> de cliente do sistema, ao tocar (ou clicar) abre uma janela com uma barra de
   pesquisa: digite parte do nome, do telefone ou do endereço e a lista se filtra na hora.

   - O <select> original continua lá, com o mesmo valor, o mesmo "change" e as mesmas opções: o resto do
     sistema (cálculos, preenchimento de telefone/endereço, envio do formulário) não percebe diferença.
   - Vale para os selects de cliente conhecidos (name client / cliente_id / ..._cliente_id; ids de modais
     montados por JavaScript) e para qualquer <select data-buscar>. Para desligar: data-sem-busca.
   - Opções como "＋ Adicionar novo cliente…" ficam sempre no fim da lista; "Sem cliente" fica no começo.
   - Teclado: Enter/Espaço/setas no campo abrem; digitar uma letra já abre pesquisando; no meio da lista,
     setas + Enter escolhem e Esc fecha. No celular a janela ocupa a tela e sobe junto com o teclado.
   ========================================================================= */
(function () {
  'use strict';
  if (window.__vnSelectBusca) return;
  window.__vnSelectBusca = true;

  var NOME_CLIENTE = /^(client|cliente(_id)?|[a-z]+_cliente_id)$/i;
  var ID_CLIENTE = /^(clientselect|novaclienteid|editclienteid)$/i;

  function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
  function ehDeCliente(s) {
    if (!s || s.tagName !== 'SELECT' || s.multiple) return false;
    if (s.hasAttribute('data-sem-busca')) return false;
    if (s.hasAttribute('data-buscar')) return true;
    return NOME_CLIENTE.test(s.name || '') || ID_CLIENTE.test(s.id || '');
  }

  /* ------------------------------------------------------------------ estilo (uma vez só) */
  function injetarCss() {
    if (document.getElementById('vnsb-css')) return;
    var st = document.createElement('style');
    st.id = 'vnsb-css';
    st.textContent =
      '.vnsb-dlg{border:0;padding:0;margin:auto;position:fixed;inset:0;overflow:hidden;width:min(540px,calc(100vw - 24px));max-height:min(78vh,660px);' +
        'background:var(--surface,var(--card,#fff));color:var(--text,#191c1d);border-radius:var(--radius-lg,14px);box-shadow:var(--shadow-lg,0 24px 70px rgba(16,24,40,.35));font-family:inherit}' +
      '.vnsb-dlg:not([open]){display:none}' +
      '.vnsb-dlg[open]{display:flex;flex-direction:column}' +
      '.vnsb-dlg::backdrop{background:rgba(15,23,42,.55);backdrop-filter:blur(3px)}' +
      '.vnsb-in{display:flex;flex-direction:column;min-height:0;flex:1}' +
      '.vnsb-h{display:flex;align-items:center;gap:8px;padding:10px 6px 6px 18px;flex:0 0 auto}' +
      '.vnsb-h strong{flex:1;min-width:0;font-size:1.02rem;font-weight:800;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
      '.vnsb-x{width:46px;height:46px;border:0;border-radius:12px;background:transparent;color:inherit;font-size:1.7rem;line-height:1;cursor:pointer}' +
      '.vnsb-x:hover{background:var(--surface-2,rgba(148,163,184,.18))}' +
      '.vnsb-busca{display:flex;align-items:center;gap:10px;margin:0 14px 6px;padding:0 14px;flex:0 0 auto;border:1px solid var(--line-strong,#d1d5db);border-radius:12px;background:var(--surface-2,#f3f4f6)}' +
      '.vnsb-busca:focus-within{border-color:var(--primary,#2563eb);box-shadow:var(--ring,0 0 0 3px rgba(37,99,235,.28))}' +
      '.vnsb-busca svg{flex:0 0 auto;width:20px;height:20px;opacity:.65}' +
      /* O campo é blindado (!important): as telas antigas (Gerar OS, Nova OS...) têm CSS de "input" que dava borda, fundo, margem e largura própria
         ao campo de dentro da barra — ele virava uma segunda barra e passava da borda da tela. */
      '.vnsb-dlg .vnsb-inp{flex:1 1 auto!important;min-width:0!important;width:auto!important;max-width:none!important;min-height:50px!important;height:auto!important;margin:0!important;padding:0!important;' +
        'border:0!important;border-radius:0!important;outline:0!important;box-shadow:none!important;background:transparent!important;color:inherit!important;font:inherit!important;font-size:16px!important;-webkit-appearance:none;appearance:none}' +
      '.vnsb-dlg .vnsb-inp:focus{border:0!important;box-shadow:none!important;background:transparent!important;outline:0!important}' +
      '.vnsb-inp::-webkit-search-cancel-button{-webkit-appearance:none;appearance:none}' +
      '.vnsb-cont{padding:2px 20px 6px;font-size:.78rem;font-weight:700;color:var(--muted,#6b7280);flex:0 0 auto}' +
      '.vnsb-lista{list-style:none;margin:0;padding:0 8px 12px;flex:1 1 auto;min-height:0;overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain}' +
      '.vnsb-lista li{display:flex;align-items:center;gap:10px;min-height:52px;padding:8px 12px;border-radius:10px;cursor:pointer;line-height:1.3}' +
      '.vnsb-lista li[hidden]{display:none}' +
      '.vnsb-lista li .t{flex:1;min-width:0;display:flex;flex-direction:column}' +
      '.vnsb-lista li .t strong{font-weight:700;overflow-wrap:anywhere}' +
      '.vnsb-lista li .t small{color:var(--muted,#6b7280);font-weight:600;font-size:.8rem;overflow-wrap:anywhere}' +
      '.vnsb-lista li .ok{flex:0 0 auto;width:22px;text-align:center;font-weight:900;color:var(--primary-fg,var(--primary,#2563eb));visibility:hidden}' +
      '.vnsb-lista li[aria-selected="true"] .ok{visibility:visible}' +
      '.vnsb-lista li[aria-selected="true"]{background:var(--primary-softer,rgba(37,99,235,.08))}' +
      '.vnsb-lista li.ativo,.vnsb-lista li:hover{background:var(--primary-soft,rgba(37,99,235,.16))}' +
      '.vnsb-lista li.acao{margin-top:6px;border:1px dashed var(--primary,#2563eb);color:var(--primary-fg,var(--primary,#2563eb));font-weight:800}' +
      '.vnsb-lista li.vazio{color:var(--text-soft,#4b5563);font-style:italic}' +
      '.vnsb-lista li.desab{opacity:.5;cursor:not-allowed}' +
      '.vnsb-vazio{margin:0;padding:26px 18px;text-align:center;color:var(--muted,#6b7280);font-weight:700}' +
      '.vnsb-sel{cursor:pointer}' +
      '@media(max-width:700px){.vnsb-dlg{width:100vw;max-width:100vw;height:100dvh;max-height:100dvh;border-radius:0}}';
    document.head.appendChild(st);
  }

  /* ------------------------------------------------------------------ a janela de pesquisa (uma só, reaproveitada) */
  var dlg, inp, lista, vazio, cont, titulo, atual = null, itens = [], ativo = -1;

  function criar() {
    injetarCss();
    dlg = document.createElement('dialog');
    dlg.className = 'vnsb-dlg';
    dlg.setAttribute('aria-labelledby', 'vnsbTitulo');
    dlg.innerHTML =
      '<div class="vnsb-in">' +
        '<div class="vnsb-h"><strong id="vnsbTitulo">Escolher cliente</strong><button type="button" class="vnsb-x" aria-label="Fechar">&times;</button></div>' +
        '<div class="vnsb-busca">' +
          '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>' +
          '<input type="search" class="vnsb-inp" placeholder="Pesquisar pelo nome ou telefone…" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search" inputmode="search" aria-label="Pesquisar cliente" role="combobox" aria-expanded="true" aria-controls="vnsbLista" autofocus>' +
        '</div>' +
        '<div class="vnsb-cont" aria-live="polite"></div>' +
        '<ul class="vnsb-lista" id="vnsbLista" role="listbox"></ul>' +
        '<p class="vnsb-vazio" hidden>Nenhum cliente encontrado.</p>' +
      '</div>';
    document.body.appendChild(dlg);
    inp = dlg.querySelector('.vnsb-inp'); lista = dlg.querySelector('.vnsb-lista'); vazio = dlg.querySelector('.vnsb-vazio');
    cont = dlg.querySelector('.vnsb-cont'); titulo = dlg.querySelector('#vnsbTitulo');

    dlg.querySelector('.vnsb-x').addEventListener('click', fechar);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) fechar(); });   // clique no fundo escuro
    dlg.addEventListener('close', function () {
      if (dlg.open) return;   // já foi aberta de novo (digitação rápida): este "close" é o da abertura anterior
      var s = atual; atual = null;
      if (s) { try { s.focus({ preventScroll: true }); } catch (e) {} }
    });
    inp.addEventListener('input', filtrar);
    inp.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); mover(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); mover(-1); }
      else if (e.key === 'Enter') { e.preventDefault(); var it = itens[ativo] || primeiroVisivel(); if (it) escolher(it); }
      else if (e.key === 'Home' && !inp.value) { e.preventDefault(); ativar(proximo(-1, 1)); }
      else if (e.key === 'End' && !inp.value) { e.preventDefault(); ativar(proximo(itens.length, -1)); }
    });
    lista.addEventListener('click', function (e) {
      var li = e.target.closest ? e.target.closest('li[data-i]') : null;
      if (!li) return;
      var it = itens[+li.getAttribute('data-i')];
      if (it && !it.desab) escolher(it);
    });
    lista.addEventListener('mousemove', function (e) {   // o mouse também "ativa" o item sob ele (setas continuam de onde parou)
      var li = e.target.closest ? e.target.closest('li[data-i]') : null;
      if (li && +li.getAttribute('data-i') !== ativo) ativar(+li.getAttribute('data-i'), true);
    });
    // celular: a janela acompanha a área visível (teclado aberto) para a lista não ficar escondida atrás dele
    if (window.visualViewport) {
      var ajustar = function () {
        if (!dlg.open) return;
        if (window.matchMedia('(max-width:700px)').matches) dlg.style.height = Math.round(window.visualViewport.height) + 'px';
        else dlg.style.height = '';
      };
      window.visualViewport.addEventListener('resize', ajustar);
      dlg.__ajustar = ajustar;
    }
  }

  function tituloDe(sel) {
    var rot = '';
    try {
      var l = sel.id ? document.querySelector('label[for="' + sel.id.replace(/"/g, '\\"') + '"]') : null;
      if (!l) l = sel.closest('label') || (sel.parentElement && sel.parentElement.querySelector(':scope > label'));
      if (l) rot = (l.textContent || '').replace(/\*/g, '').replace(/\s+/g, ' ').trim();
    } catch (e) {}
    if (!rot || rot.length > 40) rot = sel.getAttribute('aria-label') || 'Escolher cliente';
    return /cliente/i.test(rot) ? rot : 'Escolher cliente';
  }

  function montar(sel) {
    itens = [];
    lista.innerHTML = '';
    var ehObrig = sel.required;
    for (var i = 0; i < sel.options.length; i++) {
      var o = sel.options[i];
      if (o.hidden) continue;
      var v = o.value, txt = o.textContent.replace(/\s+/g, ' ').trim();
      var tipo = 'normal';
      if (v === '-1' || /^[+＋]/.test(txt)) tipo = 'acao';
      else if (v === '' || v === '0') tipo = 'vazio';
      // "Selecione um cliente…" é só um convite, não uma escolha; "Sem cliente / uso interno" é uma escolha de verdade
      if (tipo === 'vazio' && (/^selecion/i.test(txt) || (v === '' && ehObrig))) continue;
      var tel = o.getAttribute('data-tel') || o.getAttribute('data-telefone') || '';
      var end = o.getAttribute('data-end') || '';
      itens.push({
        i: itens.length, idx: i, v: v, txt: txt, tipo: tipo, desab: o.disabled,
        tel: tel, hay: norm(txt + ' ' + (o.getAttribute('data-nome') || '') + ' ' + tel + ' ' + end + ' ' + (o.getAttribute('data-email') || '')),
        digitos: (txt + ' ' + tel).replace(/\D+/g, '')
      });
    }
    // "Sem cliente" no começo, o resto na ordem do select, "＋ Adicionar…" no fim
    itens.sort(function (a, b) { var p = { vazio: 0, normal: 1, acao: 2 }; return (p[a.tipo] - p[b.tipo]) || (a.i - b.i); });
    itens.forEach(function (it, n) {
      it.i = n;
      var li = document.createElement('li');
      li.setAttribute('role', 'option'); li.setAttribute('data-i', n); li.id = 'vnsbOp' + n;
      li.className = it.tipo + (it.desab ? ' desab' : '');
      var sel1 = it.idx === sel.selectedIndex;
      li.setAttribute('aria-selected', sel1 ? 'true' : 'false');
      var t = document.createElement('span'); t.className = 't';
      var s = document.createElement('strong'); s.textContent = it.txt; t.appendChild(s);
      if (it.tel && it.tipo === 'normal') { var sm = document.createElement('small'); sm.textContent = it.tel; t.appendChild(sm); }
      li.appendChild(t);
      var ok = document.createElement('span'); ok.className = 'ok'; ok.textContent = '✓'; ok.setAttribute('aria-hidden', 'true'); li.appendChild(ok);
      it.el = li; it.sel = sel1;
      lista.appendChild(li);
    });
  }

  function visivel(it) { return !it.el.hidden; }
  function primeiroVisivel() { for (var k = 0; k < itens.length; k++) if (visivel(itens[k]) && !itens[k].desab) return itens[k]; return null; }
  function proximo(de, passo) {
    for (var k = de + passo; k >= 0 && k < itens.length; k += passo) if (visivel(itens[k]) && !itens[k].desab) return k;
    return -1;
  }
  function ativar(k, semRolar) {
    if (ativo >= 0 && itens[ativo]) { itens[ativo].el.classList.remove('ativo'); }
    ativo = k;
    if (k >= 0 && itens[k]) {
      itens[k].el.classList.add('ativo');
      inp.setAttribute('aria-activedescendant', itens[k].el.id);
      if (!semRolar) itens[k].el.scrollIntoView({ block: 'nearest' });
    } else inp.removeAttribute('aria-activedescendant');
  }
  function mover(passo) {
    var k = proximo(ativo < 0 ? (passo > 0 ? -1 : itens.length) : ativo, passo);
    if (k < 0) k = ativo;   // chegou na ponta: fica
    ativar(k);
  }

  function filtrar() {
    var termos = norm(inp.value).split(/\s+/).filter(Boolean);
    var digitos = inp.value.replace(/\D+/g, '');
    var achou = 0, total = 0;
    itens.forEach(function (it) {
      if (it.tipo === 'normal') total++;
      var ok;
      if (it.tipo === 'acao') ok = true;                          // "＋ Adicionar novo…" sempre à mão
      else if (!termos.length) ok = true;
      else if (it.tipo === 'vazio') ok = false;                   // "Sem cliente" some enquanto se pesquisa
      else ok = termos.every(function (t) { return it.hay.indexOf(t) !== -1; }) || (digitos.length >= 3 && it.digitos.indexOf(digitos) !== -1);
      it.el.hidden = !ok;
      if (ok && it.tipo === 'normal') achou++;
    });
    vazio.hidden = achou > 0 || !termos.length ? true : false;
    vazio.textContent = 'Nenhum cliente encontrado para "' + inp.value.trim() + '".';
    cont.textContent = termos.length ? (achou === 1 ? '1 cliente encontrado' : achou + ' clientes encontrados') : (total === 1 ? '1 cliente' : total + ' clientes');
    // já deixa o primeiro resultado marcado: Enter escolhe
    var p = termos.length ? itens.findIndex(function (it) { return visivel(it) && !it.desab && it.tipo === 'normal'; }) : -1;
    ativar(p, true);
    if (!termos.length) {   // sem pesquisa: mostra o escolhido (se houver) no meio da tela
      var sel = itens.find(function (it) { return it.sel; });
      if (sel) sel.el.scrollIntoView({ block: 'center' });
      else lista.scrollTop = 0;
    } else lista.scrollTop = 0;
  }

  function escolher(it) {
    var s = atual;
    if (!s) { fechar(); return; }
    var mudou = s.selectedIndex !== it.idx;
    s.selectedIndex = it.idx;
    fechar();
    // quem ouve o select (cálculos, telefone, endereço, onchange="...") continua funcionando; escolher o mesmo de novo não dispara nada
    if (mudou || it.tipo === 'acao') {
      s.dispatchEvent(new Event('input', { bubbles: true }));
      s.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function fechar() { if (dlg && dlg.open) dlg.close(); }

  function abrir(sel, inicio) {
    if (!dlg) criar();
    if (dlg.open) return;
    atual = sel;
    titulo.textContent = tituloDe(sel);
    montar(sel);
    inp.value = inicio || '';
    ativo = -1;
    filtrar();
    dlg.showModal();
    if (dlg.__ajustar) dlg.__ajustar();
    inp.focus();
    if (inicio) { try { inp.setSelectionRange(inp.value.length, inp.value.length); } catch (e) {} }
  }

  /* ------------------------------------------------------------------ liga o select (sem trocar o campo: ele só abre a pesquisa em vez da lista nativa) */
  function ligar(sel) {
    if (sel.__vnsb) return;
    sel.__vnsb = true;
    sel.classList.add('vnsb-sel');
    sel.setAttribute('aria-haspopup', 'dialog');
    if (!sel.getAttribute('title')) sel.setAttribute('title', 'Toque para pesquisar o cliente');

    sel.addEventListener('mousedown', function (e) {
      if (e.button !== 0 || sel.disabled) return;
      e.preventDefault();                     // não deixa abrir a lista nativa
      try { sel.focus({ preventScroll: true }); } catch (err) {}
      abrir(sel);
    });
    // toque: abre quando foi um toque de verdade (sem arrastar a tela)
    var ini = null;
    sel.addEventListener('touchstart', function (e) { ini = e.touches.length === 1 ? { x: e.touches[0].clientX, y: e.touches[0].clientY } : null; }, { passive: true });
    sel.addEventListener('touchend', function (e) {
      if (!ini || sel.disabled || !e.changedTouches.length) return;
      var c = e.changedTouches[0], moveu = Math.abs(c.clientX - ini.x) > 10 || Math.abs(c.clientY - ini.y) > 10;
      ini = null;
      if (moveu) return;
      e.preventDefault();                     // cancela o clique que abriria a lista nativa
      abrir(sel);
    }, { passive: false });
    sel.addEventListener('click', function (e) { if (!dlg || !dlg.open) e.preventDefault(); });
    sel.addEventListener('keydown', function (e) {
      if (sel.disabled || e.ctrlKey || e.metaKey) return;
      var k = e.key;
      if (k === 'Enter' || k === ' ' || k === 'ArrowDown' || k === 'ArrowUp' || k === 'F4') { e.preventDefault(); abrir(sel); }
      else if (k.length === 1 && /\S/.test(k) && !e.altKey) { e.preventDefault(); abrir(sel, k); }   // começou a digitar: já abre pesquisando
    });
  }

  function varrer(raiz) {
    if (!raiz || raiz.nodeType !== 1) return;
    if (raiz.tagName === 'SELECT') { if (ehDeCliente(raiz)) ligar(raiz); return; }
    var l = raiz.querySelectorAll ? raiz.querySelectorAll('select') : [];
    for (var i = 0; i < l.length; i++) if (ehDeCliente(l[i])) ligar(l[i]);
  }

  function iniciar() {
    varrer(document.body);
    // telas que desenham campos depois (janelas montadas por JavaScript, listas que se atualizam sozinhas)
    new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) for (var j = 0; j < muts[i].addedNodes.length; j++) varrer(muts[i].addedNodes[j]);
    }).observe(document.body, { childList: true, subtree: true });
  }

  window.VN = window.VN || {};
  window.VN.buscarNoSelect = function (sel) { if (sel && sel.tagName === 'SELECT') ligar(sel); };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
})();
