/* =========================================================================
   VN System — Calendário para escolher datas (A Pagar e Cobranças) · v1
   Todo campo de data (<input type="date">) da tela abre um calendário em vez do seletor do navegador: mês e ano com setas,
   dias grandes para o dedo, "Hoje" e (nos campos que não são obrigatórios) "Limpar".
   O campo continua sendo type="date": o valor segue como AAAA-MM-DD e os outros scripts da tela são avisados (input/change).
   Para um campo ficar com o seletor do navegador: data-cal="nativo".
   ========================================================================= */
(function () {
  'use strict';

  var MESES = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
  var DIAS = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'];
  var DIAS_LONGOS = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];

  var dlg = null, mesSel = null, anoSel = null, grade = null, titulo = null, sub = null, btnLimpar = null, btnHoje = null;
  var alvo = null, ano = 0, mes = 0;   // campo em edição e mês que está na tela (mes: 0 a 11)

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function iso(a, m, d) { return a + '-' + pad(m + 1) + '-' + pad(d); }
  function parse(v) { var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || ''); return m ? { a: +m[1], m: +m[2] - 1, d: +m[3] } : null; }
  function br(v) { var p = parse(v); return p ? pad(p.d) + '/' + pad(p.m + 1) + '/' + p.a : ''; }
  function hojeIso() { var h = new Date(); return iso(h.getFullYear(), h.getMonth(), h.getDate()); }
  function toast(msg) { if (window.VN && VN.toast) VN.toast(msg, 'warn'); }
  function usa(el) { return !!el && el.matches && el.matches('input[type="date"]') && el.getAttribute('data-cal') !== 'nativo' && !el.disabled; }

  function nomeDoCampo(el) {
    var l = el.id ? document.querySelector('label[for="' + el.id + '"]') : null;
    if (!l && el.closest) { var f = el.closest('.cb-f, .pd-f'); l = f ? f.querySelector('label') : null; }
    var t = (l && l.textContent) || el.getAttribute('aria-label') || '';
    return t.replace(/\s*\*\s*$/, '').trim() || 'Escolha a data';
  }

  /* ------------------------------------------------------------ janela */
  function criar() {
    if (dlg) return;
    dlg = document.createElement('dialog');
    dlg.className = 'vn-cal';
    dlg.setAttribute('aria-label', 'Calendário');
    dlg.innerHTML =
      '<div class="vn-cal-in">' +
      '<div class="vn-cal-head"><div class="vn-cal-tit"><strong data-cal-titulo></strong><small data-cal-sub></small></div>' +
      '<button type="button" class="vn-cal-x" data-cal-fechar aria-label="Fechar calendário"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></div>' +
      '<div class="vn-cal-nav">' +
      '<button type="button" class="vn-cal-seta" data-cal-passo="-1" aria-label="Mês anterior"><span class="material-symbols-outlined vn-cal-volta" aria-hidden="true">chevron_right</span></button>' +
      '<select data-cal-mes aria-label="Mês"></select><select data-cal-ano aria-label="Ano"></select>' +
      '<button type="button" class="vn-cal-seta" data-cal-passo="1" aria-label="Próximo mês"><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></button></div>' +
      '<div class="vn-cal-sem" aria-hidden="true">' + DIAS.map(function (d) { return '<span>' + d + '</span>'; }).join('') + '</div>' +
      '<div class="vn-cal-grade" data-cal-grade role="grid"></div>' +
      '<div class="vn-cal-foot"><button type="button" class="vn-cal-btn" data-cal-hoje>Hoje</button><button type="button" class="vn-cal-btn" data-cal-limpar>Limpar</button>' +
      '<span class="vn-cal-esp"></span><button type="button" class="vn-cal-btn" data-cal-fechar>Fechar</button></div>' +
      '</div>';
    document.body.appendChild(dlg);
    titulo = dlg.querySelector('[data-cal-titulo]'); sub = dlg.querySelector('[data-cal-sub]');
    mesSel = dlg.querySelector('[data-cal-mes]'); anoSel = dlg.querySelector('[data-cal-ano]');
    grade = dlg.querySelector('[data-cal-grade]'); btnLimpar = dlg.querySelector('[data-cal-limpar]'); btnHoje = dlg.querySelector('[data-cal-hoje]');
    MESES.forEach(function (n, i) { var o = document.createElement('option'); o.value = i; o.textContent = n; mesSel.appendChild(o); });

    mesSel.addEventListener('change', function () { mes = +mesSel.value; desenhar(); });
    anoSel.addEventListener('change', function () { ano = +anoSel.value; desenhar(); });
    dlg.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('button, select') : null;
      if (e.target === dlg) { fechar(); return; }                       // clique no fundo escuro
      if (!t) return;
      if (t.hasAttribute('data-cal-fechar')) { fechar(); return; }
      var passo = t.getAttribute('data-cal-passo');
      if (passo) { mes += +passo; if (mes < 0) { mes = 11; ano--; } else if (mes > 11) { mes = 0; ano++; } desenhar(); return; }
      if (t.hasAttribute('data-cal-hoje')) { var h = parse(hojeIso()); escolher(iso(h.a, h.m, h.d)); return; }
      if (t.hasAttribute('data-cal-limpar')) { escolher(''); return; }
      var dia = t.getAttribute('data-cal-dia');
      if (dia) escolher(dia);
    });
    dlg.addEventListener('keydown', function (e) {                      // setas do teclado andam pelos dias
      var b = document.activeElement;
      if (!b || !b.hasAttribute || !b.hasAttribute('data-cal-dia')) return;
      var delta = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
      if (!delta) return;
      e.preventDefault();
      var p = parse(b.getAttribute('data-cal-dia')), d = new Date(p.a, p.m, p.d + delta);
      ano = d.getFullYear(); mes = d.getMonth(); desenhar();
      var alvoBtn = grade.querySelector('[data-cal-dia="' + iso(d.getFullYear(), d.getMonth(), d.getDate()) + '"]');
      if (alvoBtn) alvoBtn.focus();
    });
    dlg.addEventListener('close', function () { alvo = null; });
  }

  function limites() { return { min: alvo.getAttribute('min') || '', max: alvo.getAttribute('max') || '' }; }

  function desenhar() {
    var lim = limites(), hoje = hojeIso(), valor = alvo.value;
    mesSel.value = String(mes);
    // anos: 10 para trás e 10 para frente do que está na tela (e sempre o ano do valor e o de hoje)
    var base = new Date().getFullYear(), ini = Math.min(base, ano) - 10, fim = Math.max(base, ano) + 10;
    if (anoSel.options.length === 0 || +anoSel.options[0].value !== ini || +anoSel.options[anoSel.options.length - 1].value !== fim) {
      anoSel.innerHTML = '';
      for (var a = ini; a <= fim; a++) { var o = document.createElement('option'); o.value = a; o.textContent = a; anoSel.appendChild(o); }
    }
    anoSel.value = String(ano);

    grade.innerHTML = '';
    var primeiro = new Date(ano, mes, 1).getDay(), total = new Date(ano, mes + 1, 0).getDate(), i;
    for (i = 0; i < primeiro; i++) grade.appendChild(document.createElement('span'));
    for (var d = 1; d <= total; d++) {
      var v = iso(ano, mes, d), b = document.createElement('button');
      b.type = 'button'; b.textContent = d; b.setAttribute('data-cal-dia', v);
      b.setAttribute('aria-label', d + ' de ' + MESES[mes].toLowerCase() + ' de ' + ano + ', ' + DIAS_LONGOS[new Date(ano, mes, d).getDay()]);
      if (v === hoje) b.className += ' hoje';
      if (v === valor) { b.className += ' sel'; b.setAttribute('aria-pressed', 'true'); }
      if ((lim.min && v < lim.min) || (lim.max && v > lim.max)) b.disabled = true;
      grade.appendChild(b);
    }
    sub.textContent = valor ? 'Selecionada: ' + br(valor) : 'Nenhuma data escolhida';
    btnLimpar.hidden = alvo.required || !valor;
    btnHoje.disabled = (lim.min && hoje < lim.min) || (lim.max && hoje > lim.max);
  }

  function abrir(input) {
    if (!usa(input) || input.readOnly === undefined) return;
    criar();
    if (dlg.open) return;
    alvo = input;
    var p = parse(input.value) || parse(hojeIso());
    ano = p.a; mes = p.m;
    titulo.textContent = nomeDoCampo(input);
    desenhar();
    if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', 'open');
    var foco = grade.querySelector('.sel') || grade.querySelector('.hoje') || grade.querySelector('button:not(:disabled)');
    if (foco) foco.focus({ preventScroll: true });
  }

  function fechar() { if (dlg && dlg.open) { try { dlg.close(); } catch (e) { dlg.removeAttribute('open'); } } }

  function escolher(valor) {
    var campo = alvo;
    fechar();
    if (!campo) return;
    campo.value = valor;
    campo.dispatchEvent(new Event('input', { bubbles: true }));    // os outros scripts da tela (parcelas, vencimento sugerido...) reagem
    campo.dispatchEvent(new Event('change', { bubbles: true }));
    try { campo.focus({ preventScroll: true }); } catch (e) { /* nada */ }
  }

  /* ------------------------------------------------------------ campos de data da página (também os criados depois) */
  // Só leitura: o seletor do navegador não abre (o calendário abre no lugar) e ninguém digita data errada.
  document.addEventListener('pointerdown', function (e) { if (usa(e.target)) e.target.readOnly = true; }, true);
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!usa(t)) return;
    e.preventDefault(); t.readOnly = true;
    abrir(t);
  }, true);
  document.addEventListener('keydown', function (e) {
    var t = e.target;
    if (!usa(t) || (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'ArrowDown')) return;
    e.preventDefault(); t.readOnly = true;
    abrir(t);
  }, true);
  // Campo obrigatório vazio: com o campo "só leitura" o navegador não cobra, então cobramos aqui.
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || !f.querySelectorAll) return;
    var vazio = Array.prototype.filter.call(f.querySelectorAll('input[type="date"][required]'), function (i) { return usa(i) && !i.value && i.offsetParent !== null; })[0];
    if (!vazio) return;
    e.preventDefault(); e.stopImmediatePropagation();
    toast('Escolha a data: ' + nomeDoCampo(vazio).toLowerCase() + '.');
    abrir(vazio);
  }, true);

  // Campo ainda não tocado (então ainda não está "só leitura") e vazio: o navegador barra o envio com o aviso dele; aqui o calendário abre no lugar.
  document.addEventListener('invalid', function (e) {
    var t = e.target;
    if (!usa(t) || t.value || !t.required) return;
    e.preventDefault();
    if (dlg && dlg.open) return;
    toast('Escolha a data: ' + nomeDoCampo(t).toLowerCase() + '.');
    abrir(t);
  }, true);

  window.VNCalendario = { abrir: abrir };
})();
