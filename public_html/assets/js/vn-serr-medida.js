/* =========================================================================
   VN System — Calcular por medida (orçamento de serralheria)
   Serralheiro cobra pela medida: portão e grade de janela por m², corrimão, guarda-corpo e gradil por metro linear,
   escada por degrau, serviço avulso por hora ou por unidade, estrutura metálica por quilo. A janela pergunta o que
   vai ser feito e a medida, aplica o nível da estrutura e o perfil da mão de obra, e põe o resultado no pedido
   (uma linha de serviço: medida × preço por medida).
   A conta (as porcentagens e os preços vêm da tela "Preços da serralheria"):
       estrutura = preço da tabela × (1 + nível %)        mão de obra = mão de obra por medida × (1 + perfil %)
       preço por medida = estrutura + mão de obra          total = medida × preço por medida
   Os dados vêm de window.VN_SERR (orcamento-novo.php) e o pedido é preenchido por window.VNPedido (vn-pedido.js).
   ========================================================================= */
(function () {
  'use strict';

  var D = window.VN_SERR, P = window.VNPedido;
  var dlg = document.getElementById('shMedida');
  var linhaRow = document.getElementById('medidaRow');
  if (!dlg) return;
  if (!D || !D.estruturas || !P) { if (linhaRow) linhaRow.remove(); return; }   // sem tabela de preços: a linha nem aparece

  function $(s, r) { return (r || dlg).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || dlg).querySelectorAll(s)); }
  var money = P.moneyBR, parse = P.parseBR;
  function r2(n) { return Math.round((Number(n) + 1e-9) * 100) / 100; }
  function fmt2(n) { return Number(n).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function fmtN(n) { return Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 2 }); }
  function unCurto(u) { return (D.unidades[u] || {}).curto || u; }
  function pctTxt(p) { p = Number(p) || 0; return p === 0 ? 'sem ajuste' : (p > 0 ? '+' : '−') + String(Math.abs(p)).replace('.', ',') + '%'; }

  var selEst = $('#medEst'), btnAdd = $('[data-med-add]'), avisoTopo = $('[data-med-aviso]');
  var inp = { larg: $('#medLarg'), alt: $('#medAlt'), pecas: $('#medPecas'), comp: $('#medComp'), deg: $('#medDeg'), horas: $('#medHoras'), kg: $('#medKg'), un: $('#medUn'), obs: $('#medObs') };
  var est = null, nivel = null, perfil = null;

  /* aviso de "sem preço" logo abaixo da escolha */
  var semPreco = document.createElement('div');
  semPreco.className = 'med-aviso'; semPreco.hidden = true;
  semPreco.innerHTML = 'Esta estrutura está sem preço na tabela. <a href="serralheria-precos.php" target="_blank" rel="noopener">Defina o preço em Preços da serralheria</a> e abra a janela de novo.';
  selEst.insertAdjacentElement('afterend', semPreco);

  /* ------------------------------------------------------------------ lista de estruturas (agrupada por categoria) */
  (function montarLista() {
    var grupos = {}, ordem = [];
    D.estruturas.forEach(function (e) { var c = e.cat || 'Outras'; if (!grupos[c]) { grupos[c] = []; ordem.push(c); } grupos[c].push(e); });
    ordem.forEach(function (c) {
      var og = document.createElement('optgroup'); og.label = c;
      grupos[c].forEach(function (e) {
        var o = document.createElement('option'); o.value = String(e.id);
        o.textContent = e.nome + (e.preco > 0 ? ' — R$ ' + money(e.preco) + '/' + unCurto(e.un) : ' — sem preço');
        og.appendChild(o);
      });
      selEst.appendChild(og);
    });
  })();

  /* ------------------------------------------------------------------ níveis e perfis (botões) */
  function montarChips(box, lista, atual, aoEscolher) {
    box.innerHTML = '';
    lista.forEach(function (it) {
      var b = document.createElement('button'); b.type = 'button'; b.setAttribute('data-k', it.k);
      b.setAttribute('aria-pressed', atual && atual.k === it.k ? 'true' : 'false');
      b.innerHTML = '<span></span><small></small>'; b.firstChild.textContent = it.nome; b.lastChild.textContent = pctTxt(it.pct);
      b.addEventListener('click', function () {
        $$('button', box).forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
        aoEscolher(it); recalcular();
      });
      box.appendChild(b);
    });
  }
  function padrao(lista, k) { return lista.filter(function (x) { return x.k === k; })[0] || lista[0] || { k: '', nome: '', pct: 0 }; }

  /* ------------------------------------------------------------------ conta */
  function estruturaAtual() { return D.estruturas.filter(function (e) { return String(e.id) === selEst.value; })[0] || null; }
  function moBase() { return est ? (est.mo !== null && est.mo !== undefined ? Number(est.mo) : Number(D.mo[est.un] || 0)) : 0; }
  function preco() {
    if (!est) return { estrutura: 0, mao: 0, unitario: 0 };
    var e = r2(Number(est.preco) * Math.max(0, 1 + (nivel ? nivel.pct : 0) / 100));
    var m = r2(moBase() * Math.max(0, 1 + (perfil ? perfil.pct : 0) / 100));
    return { estrutura: e, mao: m, unitario: r2(e + m) };
  }
  /* lê a medida digitada: { ok, qtd (na medida da estrutura), det (texto para a descrição), txt (para o resumo) } */
  function medida() {
    if (!est) return { ok: false };
    var u = est.un, q;
    if (u === 'm2') {
      var L = parse(inp.larg.value), A = parse(inp.alt.value), n = Math.max(1, Math.round(parse(inp.pecas.value) || 1));
      if (!(L > 0 && A > 0)) return { ok: false };
      var areaPeca = r2(L * A); q = r2(areaPeca * n);
      return { ok: q > 0, qtd: q, det: fmt2(L) + ' × ' + fmt2(A) + ' m' + (n > 1 ? ' · ' + n + ' peças' : ''),
               txt: (n > 1 ? n + ' × ' + fmt2(areaPeca) : fmt2(L) + ' × ' + fmt2(A)) + ' = ' + fmt2(q) + ' m²' };
    }
    var campo = u === 'm' ? inp.comp : u === 'degrau' ? inp.deg : u === 'h' ? inp.horas : u === 'kg' ? inp.kg : inp.un;
    q = r2(parse(campo.value));
    if (!(q > 0)) return { ok: false };
    return { ok: true, qtd: q, det: '', txt: fmtN(q) + ' ' + P.unRotulo(u, q) };
  }

  function recalcular() {
    var pr = preco(), m = medida(), q = m.ok ? m.qtd : 0;
    var total = r2(q * pr.unitario), totEst = r2(q * pr.estrutura), totMao = r2(total - totEst);
    $('[data-r-medida]').textContent = m.ok ? m.txt : '—';
    $('[data-r-estrutura]').textContent = 'R$ ' + money(totEst);
    $('[data-r-est-sub]').textContent = est ? 'R$ ' + money(pr.estrutura) + ' por ' + unCurto(est.un) + (nivel ? ' · nível ' + nivel.nome.toLowerCase() : '') : '';
    $('[data-r-mao]').textContent = 'R$ ' + money(totMao);
    $('[data-r-mao-sub]').textContent = est ? (moBase() > 0 ? 'R$ ' + money(pr.mao) + ' por ' + unCurto(est.un) + (perfil && perfil.k !== 'padrao' ? ' · perfil ' + perfil.nome.toLowerCase() : '') : 'não definida na tabela') : '';
    $('[data-r-unit-rot]').textContent = est ? 'Preço por ' + unCurto(est.un) : 'Preço por medida';
    $('[data-r-unit]').textContent = 'R$ ' + money(pr.unitario);
    $('[data-r-total]').textContent = 'R$ ' + money(total);
    $('[data-r-total-foot]').textContent = 'R$ ' + money(total);   // o total também fica no rodapé: aparece mesmo com o teclado aberto
    semPreco.hidden = !(est && pr.unitario <= 0);
    btnAdd.disabled = !(est && m.ok && pr.unitario > 0);
  }

  /* ------------------------------------------------------------------ janela */
  function mostrarBlocos() {
    $$('[data-med-bloco]').forEach(function (b) { b.hidden = !est || b.getAttribute('data-med-bloco') !== est.un; });
  }
  function avisar() {
    var txt = '';
    if (!D.revisado) txt = 'Os preços da tabela ainda são valores de exemplo. <a href="serralheria-precos.php" target="_blank" rel="noopener">Confira em Preços da serralheria</a> antes de passar o valor para o cliente.';
    else if (D.dias !== null && D.dias >= 30) txt = 'A tabela de preços foi conferida há ' + D.dias + ' dias. O preço do aço varia: <a href="serralheria-precos.php" target="_blank" rel="noopener">vale revisar</a>.';
    avisoTopo.innerHTML = txt; avisoTopo.hidden = !txt;
  }
  function zerar() {
    selEst.value = ''; est = null;
    Object.keys(inp).forEach(function (k) { inp[k].value = ''; });
    inp.pecas.value = '1';
    nivel = padrao(D.niveis, 'medio'); perfil = padrao(D.perfis, 'padrao');
    montarChips($('[data-med-niveis]'), D.niveis, nivel, function (it) { nivel = it; });
    montarChips($('[data-med-perfis]'), D.perfis, perfil, function (it) { perfil = it; });
    mostrarBlocos(); avisar(); recalcular();
  }

  selEst.addEventListener('change', function () { est = estruturaAtual(); mostrarBlocos(); recalcular(); });
  Object.keys(inp).forEach(function (k) { inp[k].addEventListener('input', recalcular); });
  $('[data-med-menos]').addEventListener('click', function () { inp.pecas.value = String(Math.max(1, (Math.round(parse(inp.pecas.value)) || 1) - 1)); recalcular(); });
  $('[data-med-mais]').addEventListener('click', function () { inp.pecas.value = String((Math.round(parse(inp.pecas.value)) || 0) + 1); recalcular(); });
  dlg.addEventListener('keydown', function (e) {   // Enter não pode salvar o pedido inteiro: ele só confirma o cálculo
    if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); if (!btnAdd.disabled) btnAdd.click(); }
  });
  if (linhaRow) linhaRow.addEventListener('click', zerar);   // cada abertura começa do zero (o clique também abre a janela, em vn-pedido.js)

  btnAdd.addEventListener('click', function () {
    var m = medida(), pr = preco();
    if (!est || !m.ok || pr.unitario <= 0) return;
    var obs = inp.obs.value.trim();
    var det = [m.det, nivel && nivel.k !== 'medio' ? 'nível ' + nivel.nome.toLowerCase() : '', obs].filter(Boolean).join(' · ');
    P.adicionar({
      tipo: est.un === 'h' ? 'mao_obra' : 'servico', desc: est.nome + (det ? ' — ' + det : ''),
      qtd: m.qtd, preco: pr.unitario, custo: 0, origem: 'estoque', linha: 'serralheria', un: est.un
    });
    var nome = est.nome, total = r2(m.qtd * pr.unitario);
    P.fechar(dlg);   // fecha antes do aviso: o aviso mora na janela aberta
    P.toast('Adicionado ao pedido: ' + nome + ' · R$ ' + money(total), 'ok');
  });

  zerar();
})();
