/* =========================================================================
   VN System — Informar a medida (produto cobrado por medida, no orçamento)
   Serralheiro cobra pela medida: portão e grade de janela por m², corrimão e guarda-corpo por metro linear, escada por
   degrau, serviço por hora, estrutura por quilo. Não existe tabela de preços nem estrutura pronta: o PRODUTO define como
   se cobra e o valor de cada medida quando é cadastrado (aba "Cadastrar" do orçamento).
   Quando a pessoa toca num produto assim no catálogo (vn-pedido.js), esta janela pergunta a medida (largura × altura,
   comprimento, degraus, horas ou peso), faz a conta (medida × valor da medida) e põe uma linha no pedido.
   O valor da medida vem do cadastro do produto, mas pode ser ajustado aqui só para este pedido.
   A leitura da medida e as medidas conhecidas ficam em window.VNPedido (vn-pedido.js).
   ========================================================================= */
(function () {
  'use strict';

  var P = window.VNPedido;
  var dlg = document.getElementById('shMedida');
  if (!dlg || !P) return;

  function $(s) { return dlg.querySelector(s); }
  function $$(s) { return Array.prototype.slice.call(dlg.querySelectorAll(s)); }
  var money = P.moneyBR, parse = P.parseBR, r2 = P.r2;

  var inp = { larg: $('#medLarg'), alt: $('#medAlt'), pecas: $('#medPecas'), comp: $('#medComp'), deg: $('#medDeg'), horas: $('#medHoras'), kg: $('#medKg'), preco: $('#medPreco'), obs: $('#medObs') };
  var btnAdd = $('[data-med-add]');
  var item = null, opcoes = {};

  function valores() {
    return { larg: inp.larg.value, alt: inp.alt.value, pecas: inp.pecas.value, comp: inp.comp.value, deg: inp.deg.value, horas: inp.horas.value, kg: inp.kg.value };
  }
  function unCurto() { return item ? (P.calculos[item.calculo] || item.calculo) : ''; }

  function recalcular() {
    var m = item ? P.lerMedida(item.calculo, valores()) : { ok: false };
    var preco = parse(inp.preco.value);
    var total = m.ok ? r2(m.qtd * preco) : 0;
    $('[data-r-medida]').textContent = m.ok ? m.txt : '—';
    $('[data-r-unit]').textContent = 'R$ ' + money(preco);
    $('[data-r-total]').textContent = 'R$ ' + money(total);
    $('[data-r-total-foot]').textContent = 'R$ ' + money(total);   // o total também fica no rodapé: aparece mesmo com o teclado aberto
    btnAdd.disabled = !(item && m.ok && preco > 0);
  }

  /* abre a janela para um produto do catálogo (it: item do catálogo com .calculo; o: { tipo, linha }) */
  function abrir(it, o) {
    if (!it || !P.unCalc(it.calculo)) return;
    item = it; opcoes = o || {};
    Object.keys(inp).forEach(function (k) { inp[k].value = ''; });
    inp.pecas.value = '1';
    inp.preco.value = money(Number(it.valor_unitario) || 0);
    $('[data-med-nome]').textContent = it.nome || '';
    var det = String(it.descricao || '').trim();
    $('[data-med-det]').textContent = det && det !== it.nome ? det : '';
    $('[data-med-preco-rot]').textContent = 'Preço por ' + unCurto() + ' (R$)';
    $('[data-r-unit-rot]').textContent = 'Preço por ' + unCurto();
    $$('[data-med-bloco]').forEach(function (b) { b.hidden = b.getAttribute('data-med-bloco') !== it.calculo; });
    recalcular();
    P.abrir(dlg);
    var primeiro = it.calculo === 'm2' ? inp.larg : it.calculo === 'm' ? inp.comp : it.calculo === 'degrau' ? inp.deg : it.calculo === 'h' ? inp.horas : inp.kg;
    if (primeiro && !window.matchMedia('(max-width:900px)').matches) primeiro.focus();
  }

  Object.keys(inp).forEach(function (k) { inp[k].addEventListener('input', recalcular); });
  $('[data-med-menos]').addEventListener('click', function () { inp.pecas.value = String(Math.max(1, (Math.round(parse(inp.pecas.value)) || 1) - 1)); recalcular(); });
  $('[data-med-mais]').addEventListener('click', function () { inp.pecas.value = String((Math.round(parse(inp.pecas.value)) || 0) + 1); recalcular(); });
  dlg.addEventListener('keydown', function (e) {   // Enter não pode salvar o pedido inteiro: ele só confirma o cálculo
    if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); if (!btnAdd.disabled) btnAdd.click(); }
  });

  btnAdd.addEventListener('click', function () {
    if (!item) return;
    var m = P.lerMedida(item.calculo, valores()), preco = parse(inp.preco.value);
    if (!m.ok || preco <= 0) return;
    var cat = String(item.descricao || '').trim();
    if (cat === item.nome) cat = '';
    var det = [cat, m.det, inp.obs.value.trim()].filter(Boolean).join(' · ');
    P.adicionar({
      tipo: opcoes.tipo || item.tipo || 'outro', desc: item.nome + (det ? ' — ' + det : ''),
      qtd: m.qtd, preco: preco, custo: Number(item.custo_unitario) || 0, origem: 'estoque', linha: opcoes.linha || P.categoria(), un: item.calculo
    });
    var nome = item.nome, total = r2(m.qtd * preco);
    P.fechar(dlg);   // fecha antes do aviso: o aviso mora na janela aberta
    P.toast('Adicionado ao pedido: ' + nome + ' · R$ ' + money(total), 'ok');
  });

  window.VNMedida = { abrir: abrir };
})();
