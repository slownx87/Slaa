/* =========================================================================
   VN System — Tela de Pedido / Orçamento (orcamento-novo.php) · v1
   Itens compactos + janela de edição, desconto por serviço/peça, cliente rápido,
   fotos (reduzidas antes do envio), calendário, textos padrão e validações.
   Tipo do orçamento (CFTV | Serralheria): troca quais linhas de peça aparecem; "Peças de serralheria" é um grupo
   à parte (com o catálogo de serralheria), e os serviços valem para os dois tipos.
   O envio é o formulário normal (POST) com os mesmos nomes de campo de sempre.
   ========================================================================= */
(function () {
  'use strict';

  var CFG = window.VN_PEDIDO || {};
  var VN = window.VN || {};   // o script é defer e vem depois do vn-ui.js
  var form = document.getElementById('pedidoForm');
  if (!form) return;
  var dirty = false;

  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function moneyBR(n) { return Number(n || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function parseBR(v) {
    v = String(v == null ? '' : v).replace(/[^0-9,.\-]/g, '');
    if (v.indexOf(',') !== -1) v = v.replace(/\./g, '').replace(',', '.');
    var n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  }
  function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
  function fmtQtd(n) { return Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 2 }); }
  function toast(msg, tipo, ms) { if (VN.toast) return VN.toast(msg, tipo, ms); window.alert(msg); return null; }
  function confirmar(msg, opts) { return VN.confirm ? VN.confirm(msg, opts) : Promise.resolve(window.confirm(msg)); }
  function marcarAlterado() { dirty = true; }

  /* Janela modal fica acima de tudo: o aviso (toast) precisa morar dentro dela para aparecer. */
  if (VN.toast && !VN.toast.__pd) {
    var toastOriginal = VN.toast;
    VN.toast = function () {
      var t = toastOriginal.apply(VN, arguments);
      var host = document.querySelector('.vn-toast-host');
      if (host) { var alvo = document.querySelector('dialog[open]') || document.body; if (host.parentNode !== alvo) alvo.appendChild(host); }
      return t;
    };
    VN.toast.__pd = true;
  }

  /* ------------------------------------------------------------------ janelas */
  function abrir(id) {
    var d = typeof id === 'string' ? document.getElementById(id) : id;
    if (!d || d.open) return d;
    if (d.showModal) d.showModal(); else d.setAttribute('open', 'open');
    return d;
  }
  function fechar(d) { if (d && d.open) d.close(); }
  document.addEventListener('click', function (e) {
    var t = e.target;
    var f = t.closest('[data-fechar]'); if (f) { fechar(f.closest('dialog')); return; }
    var a = t.closest('[data-abrir]'); if (a) { e.preventDefault(); abrir(a.getAttribute('data-abrir')); return; }
    if (t.matches && t.matches('dialog.pd-sheet.pequena')) fechar(t);   // clique no fundo (PC)
  });

  /* ------------------------------------------------------------------ seções e campos que abrem */
  document.addEventListener('click', function (e) {
    var h = e.target.closest('[data-secao]');
    if (h) {
      var sec = h.closest('.pd-sec');
      sec.classList.toggle('fechada');
      h.setAttribute('aria-expanded', sec.classList.contains('fechada') ? 'false' : 'true');
      return;
    }
    var r = e.target.closest('[data-reveal]');
    if (r) {
      var alvo = document.getElementById(r.getAttribute('data-reveal'));
      if (!alvo) return;
      var aberto = alvo.classList.toggle('aberto');
      r.setAttribute('aria-expanded', aberto ? 'true' : 'false');
      if (aberto) { var f = $('input,textarea', alvo); if (f && !window.matchMedia('(max-width:900px)').matches) f.focus(); }
    }
  });

  /* ------------------------------------------------------------------ itens do pedido */
  var TIPOS = { material: 'Material', equipamento: 'Equipamento', produto_usado: 'Produto usado', servico: 'Serviço', mao_obra: 'Mão de obra', outro: 'Outro' };
  var boxServ = document.getElementById('itemsServicos'), boxPeca = document.getElementById('itemsPecas'), boxSerr = document.getElementById('itemsSerralheria');
  var categoria = CFG.categoria === 'serralheria' ? 'serralheria' : 'cftv';
  function todosItens() { return $$('.pd-item'); }
  var itemAtual = null;

  function campo(el, nome) { return $('input[name="' + nome + '[]"]', el); }
  function lerItem(el) {
    return {
      tipo: campo(el, 'item_tipo').value, desc: campo(el, 'item_desc').value,
      qtd: parseBR(campo(el, 'item_qtd').value) || 1, preco: parseBR(campo(el, 'item_preco').value),
      custo: parseBR(campo(el, 'item_custo').value), origem: campo(el, 'item_origem').value,
      linha: (campo(el, 'item_linha') || {}).value === 'serralheria' ? 'serralheria' : 'cftv'
    };
  }
  function ehServico(tipo) { return tipo === 'servico' || tipo === 'mao_obra'; }
  function desenharItem(el) {
    var d = lerItem(el);
    // "Nome — detalhes": o nome é o título do cartão e os detalhes ficam logo abaixo (2 linhas)
    var partes = d.desc.split(' — '), nome = partes.shift(), det = partes.join(' — ');
    var n = $('.n', el); n.textContent = nome;
    var dt = $('.dt', el);
    if (!dt) { dt = document.createElement('span'); dt.className = 'dt'; n.insertAdjacentElement('afterend', dt); }
    dt.textContent = det; dt.hidden = !det;
    var sub = fmtQtd(d.qtd) + ' × R$ ' + moneyBR(d.preco) + ' · ' + (TIPOS[d.tipo] || 'Item');
    var s = $('.s', el);
    s.textContent = sub;
    if (!ehServico(d.tipo) && d.origem === 'comprar') { var tag = document.createElement('span'); tag.className = 'pd-tag comprar'; tag.textContent = 'Comprar'; s.appendChild(tag); }
    $('.t', el).textContent = 'R$ ' + moneyBR(d.qtd * d.preco);
  }
  function novoItemEl(d) {
    var el = document.createElement('div');
    el.className = 'pd-item'; el.setAttribute('data-item', ''); el.tabIndex = 0; el.setAttribute('role', 'button'); el.setAttribute('aria-label', 'Editar item');
    function h(nome, valor) { var i = document.createElement('input'); i.type = 'hidden'; i.name = nome + '[]'; i.value = valor; el.appendChild(i); }
    h('item_tipo', d.tipo || 'material'); h('item_desc', d.desc || ''); h('item_qtd', String(d.qtd || 1)); h('item_preco', moneyBR(d.preco || 0));
    h('item_custo', moneyBR(d.custo || 0)); h('item_origem', ehServico(d.tipo) ? 'estoque' : (d.origem || 'estoque'));
    h('item_linha', d.linha === 'serralheria' ? 'serralheria' : 'cftv');
    el.insertAdjacentHTML('beforeend', '<span class="n"></span><span class="s"></span><span class="t"></span>' +
      '<button type="button" class="x" data-item-remover aria-label="Remover item"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>');
    return el;
  }
  /* Item do catálogo vira "Nome — detalhes" (igual ao cadastro na hora); sem detalhe diferente do nome, só o nome. */
  function nomeComDetalhe(it) {
    var nome = String(it.nome || '').trim(), det = String(it.descricao || '').trim();
    if (!nome) return det;
    if (!det || det === nome || nome.indexOf(det) === 0) return nome;   // sem detalhe, igual ao nome ou só o nome cortado
    if (det.indexOf(nome) === 0) return det;                              // o detalhe já começa pelo nome
    return nome + ' — ' + det;
  }
  // serviços ficam embaixo de "Serviços"; peças embaixo de "Peças" (CFTV) ou "Peças de serralheria", conforme a linha do item
  function caixaDo(tipo, linha) { return ehServico(tipo) ? boxServ : (linha === 'serralheria' ? boxSerr : boxPeca); }
  function adicionarItem(d) { var el = novoItemEl(d); caixaDo(d.tipo, d.linha).appendChild(el); desenharItem(el); calcular(); marcarAlterado(); return el; }
  function removerItem(el) {
    confirmar('Remover "' + (lerItem(el).desc || 'este item') + '" do pedido?', { danger: true, okText: 'Remover' }).then(function (ok) {
      if (!ok) return;
      el.remove(); fechar(document.getElementById('shItem')); calcular(); marcarAlterado(); atualizarListasAbertas();
    });
  }

  /* custo × margem × markup × preço (mesma conta do Inventário: preço = custo / (1 − margem)) */
  function ligarPreco(c) {   // c: {preco, custo, lucro, markup} (elementos)
    function ajustaPct() {
      var cu = parseBR(c.custo.value), p = parseBR(c.preco.value);
      if (cu > 0 && p > 0) { c.lucro.value = moneyBR(Math.max(0, ((p - cu) / p) * 100)); if (c.markup) c.markup.value = moneyBR(Math.max(0, ((p - cu) / cu) * 100)); }
    }
    function doLucro() {
      var cu = parseBR(c.custo.value), m = parseBR(c.lucro.value);
      if (cu > 0 && m > 0 && m < 100) { c.preco.value = moneyBR(cu / (1 - m / 100)); if (c.markup) c.markup.value = moneyBR(((parseBR(c.preco.value) - cu) / cu) * 100); }
      if (c.onChange) c.onChange();
    }
    function doMarkup() {
      var cu = parseBR(c.custo.value), mk = parseBR(c.markup.value);
      if (cu > 0 && mk > 0) { c.preco.value = moneyBR(cu * (1 + mk / 100)); var p = parseBR(c.preco.value); c.lucro.value = moneyBR(Math.max(0, ((p - cu) / p) * 100)); }
      if (c.onChange) c.onChange();
    }
    c.custo.addEventListener('input', function () { if (parseBR(c.lucro.value) > 0) doLucro(); else if (c.markup && parseBR(c.markup.value) > 0) doMarkup(); else ajustaPct(); if (c.onChange) c.onChange(); });
    c.lucro.addEventListener('input', doLucro);
    if (c.markup) c.markup.addEventListener('input', doMarkup);
    c.preco.addEventListener('input', function () { ajustaPct(); if (c.onChange) c.onChange(); });
  }

  /* janela de edição do item */
  var sh = { desc: $('#iDesc'), tipo: $('#iTipo'), origem: $('#iOrigem'), qtd: $('#iQtd'), preco: $('#iPreco'), custo: $('#iCusto'), lucro: $('#iLucro'), markup: $('#iMarkup'), valor: $('#iValor') };
  function valorDoItem() { return Math.max(0.01, parseBR(sh.qtd.value) || 1) * parseBR(sh.preco.value); }
  function atualizaValorItem() { sh.valor.textContent = 'R$ ' + moneyBR(valorDoItem()); }
  function origemVisivel() { $('#iOrigemBox').hidden = ehServico(sh.tipo.value); }
  ligarPreco({ preco: sh.preco, custo: sh.custo, lucro: sh.lucro, markup: sh.markup, onChange: atualizaValorItem });
  sh.qtd.addEventListener('input', atualizaValorItem);
  sh.tipo.addEventListener('change', origemVisivel);
  $('[data-i-menos]').addEventListener('click', function () { sh.qtd.value = fmtQtd(Math.max(1, (parseBR(sh.qtd.value) || 1) - 1)); atualizaValorItem(); });
  $('[data-i-mais]').addEventListener('click', function () { sh.qtd.value = fmtQtd((parseBR(sh.qtd.value) || 0) + 1); atualizaValorItem(); });

  function editarItem(el) {
    itemAtual = el;
    var d = lerItem(el);
    sh.desc.value = d.desc; sh.tipo.value = d.tipo; sh.origem.value = d.origem || 'estoque'; sh.qtd.value = fmtQtd(d.qtd);
    sh.preco.value = moneyBR(d.preco); sh.custo.value = moneyBR(d.custo);
    sh.lucro.value = ''; sh.markup.value = '';
    if (d.custo > 0 && d.preco > 0) { sh.lucro.value = moneyBR(Math.max(0, ((d.preco - d.custo) / d.preco) * 100)); sh.markup.value = moneyBR(Math.max(0, ((d.preco - d.custo) / d.custo) * 100)); }
    origemVisivel(); atualizaValorItem();
    abrir('shItem');
  }
  $('[data-i-salvar]').addEventListener('click', function () {
    if (!itemAtual) return;
    var desc = sh.desc.value.trim();
    if (!desc) { toast('Escreva a descrição do item.', 'warn'); sh.desc.focus(); return; }
    campo(itemAtual, 'item_desc').value = desc; campo(itemAtual, 'item_tipo').value = sh.tipo.value;
    campo(itemAtual, 'item_qtd').value = String(Math.max(0.01, parseBR(sh.qtd.value) || 1));
    campo(itemAtual, 'item_preco').value = moneyBR(parseBR(sh.preco.value)); campo(itemAtual, 'item_custo').value = moneyBR(parseBR(sh.custo.value));
    campo(itemAtual, 'item_origem').value = ehServico(sh.tipo.value) ? 'estoque' : sh.origem.value;
    var destino = caixaDo(sh.tipo.value, lerItem(itemAtual).linha);
    if (itemAtual.parentNode !== destino) destino.appendChild(itemAtual);   // mudou o tipo: troca de grupo
    desenharItem(itemAtual); calcular(); marcarAlterado(); fechar(document.getElementById('shItem')); atualizarListasAbertas();
  });
  $('[data-i-remover]').addEventListener('click', function () { if (itemAtual) removerItem(itemAtual); });

  var secPedido = document.getElementById('secPedido');
  secPedido.addEventListener('click', function (e) {
    var x = e.target.closest('[data-item-remover]');
    var el = e.target.closest('[data-item]');
    if (!el) return;
    if (x) { e.stopPropagation(); removerItem(el); return; }
    editarItem(el);
  });
  secPedido.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('[data-item]')) { e.preventDefault(); editarItem(e.target); }
  });

  /* ------------------------------------------------------------------ total e desconto */
  var desc = { servico: { tipo: '%', valor: 0 }, peca: { tipo: '%', valor: 0 }, legado: 0 };
  (function iniciaDesconto() {
    var c = CFG.descCfg || {};
    ['servico', 'peca'].forEach(function (k) { if (c[k] && c[k].valor > 0) desc[k] = { tipo: c[k].tipo === 'R$' ? 'R$' : '%', valor: Number(c[k].valor) }; });
    var salvo = parseBR($('#descontoInput').value);
    if (!desc.servico.valor && !desc.peca.valor && salvo > 0) desc.legado = salvo;   // pedido antigo: só tinha o valor total
  })();

  function bases() {
    var serv = 0, peca = 0;
    todosItens().forEach(function (el) { var d = lerItem(el), t = d.qtd * d.preco; if (ehServico(d.tipo)) serv += t; else peca += t; });
    serv += parseBR(($('#vValor') || {}).value) + parseBR(($('#mValor') || {}).value);
    return { serv: serv, peca: peca };
  }
  function valorDesconto(d, base) { return d.tipo === '%' ? base * Math.min(100, Math.max(0, d.valor)) / 100 : Math.max(0, d.valor); }
  function descontoTotal(estado, b) { return valorDesconto(estado.servico, b.serv) + valorDesconto(estado.peca, b.peca) + (estado.legado || 0); }

  function calcular() {
    var b = bases(), sub = b.serv + b.peca, d = Math.min(sub, descontoTotal(desc, b));
    var total = Math.max(0, sub - d);
    $('#totalView').textContent = 'R$ ' + moneyBR(total);
    $('#totalSub').textContent = d > 0 ? 'Subtotal R$ ' + moneyBR(sub) + ' · desconto -R$ ' + moneyBR(d) : '';
    $('#descontoInput').value = moneyBR(d);
    var cfg = {};
    ['servico', 'peca'].forEach(function (k) { if (desc[k].valor > 0) cfg[k] = { tipo: desc[k].tipo, valor: desc[k].valor }; });
    $('#descontoCfg').value = Object.keys(cfg).length ? JSON.stringify(cfg) : '';
    $('#descontoResumo').textContent = d > 0 ? '-R$ ' + moneyBR(d) : 'Nenhum';
    [[boxServ, '#servResumo', 'servicos'], [boxPeca, '#pecaResumo', 'pecas'], [boxSerr, '#serrResumo', 'serralheria']].forEach(function (g) {
      var els = $$('.pd-item', g[0]), soma = 0;
      els.forEach(function (el) { var i = lerItem(el); soma += i.qtd * i.preco; });
      var r = $(g[1]), linha = $('[data-grupo-abrir="' + g[2] + '"]');
      r.textContent = els.length ? els.length + (els.length === 1 ? ' item' : ' itens') + ' · R$ ' + moneyBR(soma) : r.getAttribute('data-dica');
      $('.pd-plus', linha).hidden = els.length > 0; $('.pd-go', linha).hidden = els.length === 0;
    });
  }
  document.addEventListener('input', function (e) { if (e.target.matches && e.target.matches('.js-extra')) { calcular(); marcarAlterado(); } });

  /* janela do desconto: edita uma cópia e só vale ao "salvar desconto" */
  var work = null;
  function blocoDesc(k) { return $('[data-desc="' + k + '"]'); }
  function desenharDesc() {
    var b = bases();
    ['servico', 'peca'].forEach(function (k) {
      var bl = blocoDesc(k), w = work[k], pct = w.tipo === '%';
      $$('.pd-seg button', bl).forEach(function (x) { x.setAttribute('aria-pressed', x.getAttribute('data-tipo') === w.tipo ? 'true' : 'false'); });
      var rap = $$('[data-rapidos] button', bl);
      var valores = pct ? [5, 10] : [50, 100];
      rap.forEach(function (x, i) { x.setAttribute('data-v', valores[i]); x.textContent = pct ? valores[i] + '%' : 'R$ ' + valores[i]; x.setAttribute('aria-pressed', w.valor === valores[i] ? 'true' : 'false'); });
      var out = $('[data-outro]', bl);
      out.placeholder = pct ? 'Outros (%)' : 'Outros (R$)';
      var rapidoAtivo = rap.some(function (x) { return x.getAttribute('aria-pressed') === 'true'; });
      if (document.activeElement !== out) out.value = (w.valor > 0 && !rapidoAtivo) ? moneyBR(w.valor) : '';
      $('[data-desc-base]', bl).textContent = '(base R$ ' + moneyBR(k === 'servico' ? b.serv : b.peca) + ')';
    });
    $('#descontoTotalView').textContent = '-R$ ' + moneyBR(Math.min(b.serv + b.peca, descontoTotal(work, b)));
  }
  $('[data-abrir="shDesconto"]').addEventListener('click', function () {
    work = JSON.parse(JSON.stringify(desc)); desenharDesc();
  });
  $$('[data-desc]').forEach(function (bl) {
    var k = bl.getAttribute('data-desc');
    $$('.pd-seg button', bl).forEach(function (btn) {
      btn.addEventListener('click', function () { work[k].tipo = btn.getAttribute('data-tipo'); work[k].valor = 0; work.legado = 0; desenharDesc(); });
    });
    $$('[data-rapidos] button', bl).forEach(function (btn) {
      btn.addEventListener('click', function () { var v = Number(btn.getAttribute('data-v')); work[k].valor = work[k].valor === v ? 0 : v; work.legado = 0; $('[data-outro]', bl).value = ''; desenharDesc(); });
    });
    $('[data-outro]', bl).addEventListener('input', function (e) { work[k].valor = parseBR(e.target.value); work.legado = 0; desenharDesc(); });
  });
  $('[data-desc-salvar]').addEventListener('click', function () { desc = JSON.parse(JSON.stringify(work)); calcular(); marcarAlterado(); fechar(document.getElementById('shDesconto')); });
  $('[data-desc-limpar]').addEventListener('click', function () {
    desc = { servico: { tipo: '%', valor: 0 }, peca: { tipo: '%', valor: 0 }, legado: 0 }; calcular(); marcarAlterado(); fechar(document.getElementById('shDesconto'));
  });

  /* ------------------------------------------------------------------ janelas "adicionar peça / serviço" */
  $$('dialog[data-tipo-base]').forEach(function (modal) {
    var rotulo = modal.getAttribute('data-rotulo') || 'item';
    var tipoBase = modal.getAttribute('data-tipo-base') || 'material';
    var linhaModal = modal.getAttribute('data-linha') || '';   // 'cftv' | 'serralheria' | '' (serviços: vale para os dois)
    var okBtn = $('[data-add-confirmar]', modal);
    var aba = 'cat';
    function q(s) { return $(s, modal); }

    var ehModalServico = tipoBase === 'servico';
    function itemDoCard(card) {
      return (CFG.catalogo || []).filter(function (x) { return String(x.id) === card.getAttribute('data-id') && String(x.origem) === card.getAttribute('data-origem'); })[0];
    }
    /* itens do pedido que vieram deste cartão do catálogo (mesmo texto de linha e mesmo grupo) */
    function itensDoCard(card) {
      var it = itemDoCard(card), esperado = it ? nomeComDetalhe(it) : '';
      return esperado ? todosItens().filter(function (el) { var d = lerItem(el); return d.desc === esperado && ehServico(d.tipo) === ehModalServico && (ehModalServico || d.linha === linhaModal); }) : [];
    }
    /* ao abrir: o que já está no pedido aparece marcado (com a quantidade), e desmarcar remove do pedido */
    function sincronizarSelecao() {
      $$('[data-sel]', modal).forEach(function (card) {
        var itens = itensDoCard(card), no = itens.length > 0;
        card.classList.toggle('marcado', no); card.setAttribute('data-inicial', no ? '1' : '0');
        var tag = $('.no-pedido', card);
        if (!no) { if (tag) tag.remove(); return; }
        var qtd = itens.reduce(function (t, el) { return t + lerItem(el).qtd; }, 0);
        if (!tag) { tag = document.createElement('small'); tag.className = 'no-pedido'; $('.info', card).appendChild(tag); }
        tag.textContent = 'No pedido · ' + fmtQtd(qtd) + (qtd === 1 ? ' unidade' : ' unidades');
      });
      sincronizar();
    }
    modal.__sincronizarSelecao = sincronizarSelecao;
    function diferencas() {
      var add = 0, rem = 0;
      $$('[data-sel]', modal).forEach(function (c) { var m = c.classList.contains('marcado'), i = c.getAttribute('data-inicial') === '1'; if (m && !i) add++; else if (!m && i) rem++; });
      return { add: add, rem: rem };
    }
    function novoValido() { return q('[data-new-nome]').value.trim() !== '' && parseBR(q('[data-new-preco]').value) > 0; }
    function sincronizar() {
      if (aba === 'cat') {
        var df = diferencas();
        okBtn.textContent = df.rem > 0 ? 'aplicar alterações (' + (df.add + df.rem) + ')' : (df.add > 0 ? 'adicionar selecionadas (' + df.add + ')' : 'adicionar');
        okBtn.disabled = df.add + df.rem === 0;
      }
      else { okBtn.textContent = 'adicionar ' + (rotulo.indexOf('peça') === 0 ? 'essa ' : 'esse ') + rotulo; okBtn.disabled = !novoValido(); }
    }
    $$('[data-add-tab]', modal).forEach(function (t) {
      t.addEventListener('click', function () {
        aba = t.getAttribute('data-add-tab');
        $$('[data-add-tab]', modal).forEach(function (x) { x.classList.toggle('on', x === t); });
        $$('[data-tab-body]', modal).forEach(function (b) { b.hidden = b.getAttribute('data-tab-body') !== aba; });
        sincronizar();
      });
    });
    $$('[data-sel]', modal).forEach(function (card) { card.addEventListener('click', function () { card.classList.toggle('marcado'); sincronizar(); }); });
    q('[data-add-busca]').addEventListener('input', function (e) {
      var termo = norm(e.target.value).trim();
      $$('[data-sel]', modal).forEach(function (c) { c.hidden = (termo !== '' && norm(c.getAttribute('data-busca')).indexOf(termo) === -1) || c.getAttribute('data-fora-linha') === '1'; });
      $$('[data-grupo]', modal).forEach(function (g) { g.hidden = !$$('[data-sel]', g).some(function (c) { return !c.hidden; }); });
    });

    /* cadastrar */
    var qtdEl = q('[data-new-qtd]'), precoEl = q('[data-new-preco]'), custoEl = q('[data-new-custo]'), lucroEl = q('[data-new-lucro]'), markEl = q('[data-new-markup]');
    function novoValor() {
      q('[data-new-valor]').textContent = 'R$ ' + moneyBR(parseBR(precoEl.value) * Math.max(1, parseBR(qtdEl.value)));
      sincronizar();
    }
    q('[data-new-menos]').addEventListener('click', function () { qtdEl.value = fmtQtd(Math.max(1, parseBR(qtdEl.value) - 1)); novoValor(); });
    q('[data-new-mais]').addEventListener('click', function () { qtdEl.value = fmtQtd(parseBR(qtdEl.value) + 1); novoValor(); });
    ['[data-new-preco]', '[data-new-qtd]', '[data-new-nome]'].forEach(function (s) { q(s).addEventListener('input', novoValor); });
    ligarPreco({ preco: precoEl, custo: custoEl, lucro: lucroEl, markup: markEl, onChange: novoValor });
    var tg = q('[data-new-cl-toggle]');
    tg.addEventListener('click', function () {
      var corpo = q('[data-new-cl-body]'); corpo.hidden = !corpo.hidden;
      tg.classList.toggle('fechada', corpo.hidden); tg.setAttribute('aria-expanded', corpo.hidden ? 'false' : 'true');
    });
    var fotoBtn = q('[data-new-foto-btn]'), fotoEl = q('[data-new-foto]');
    if (fotoBtn && fotoEl) {
      fotoBtn.addEventListener('click', function () { fotoEl.click(); });
      fotoEl.addEventListener('change', function () {
        var nome = fotoEl.files && fotoEl.files[0] ? fotoEl.files[0].name : 'A foto vai para o catálogo';
        $('[data-new-foto-nome]', modal).textContent = nome;
        if (fotoEl.files && fotoEl.files.length) q('[data-new-salvar]').checked = true;
      });
    }

    okBtn.addEventListener('click', function () {
      if (aba === 'cat') {
        $$('[data-sel]', modal).forEach(function (card) {
          var marcado = card.classList.contains('marcado'), inicial = card.getAttribute('data-inicial') === '1';
          if (marcado && !inicial) {
            var it = itemDoCard(card);
            if (it) adicionarItem({ tipo: it.tipo || tipoBase, desc: nomeComDetalhe(it), qtd: 1, preco: Number(it.valor_unitario) || 0, custo: Number(it.custo_unitario) || 0, origem: 'estoque', linha: linhaModal || categoria });
          } else if (!marcado && inicial) {
            itensDoCard(card).forEach(function (el) { el.remove(); });
            calcular(); marcarAlterado();
          }
        });
        sincronizarSelecao(); fechar(modal); atualizarListasAbertas(); return;
      }
      if (!novoValido()) return;
      var nome = q('[data-new-nome]').value.trim();
      var tipoSel = q('[data-new-tipo]').value || tipoBase;
      var det = q('[data-new-det]').value.trim();
      var un = q('[data-new-un]').value.trim() || 'un';
      var preco = parseBR(precoEl.value), qtd = Math.max(1, parseBR(qtdEl.value)), custo = parseBR(custoEl.value);
      var origemEl = q('[data-new-origem]');
      adicionarItem({ tipo: tipoSel, desc: nome + (det ? ' — ' + det : ''), qtd: qtd, preco: preco, custo: custo, origem: origemEl ? origemEl.value : 'estoque', linha: linhaModal || categoria });

      if (q('[data-new-salvar]').checked) {
        var fd = new FormData();
        fd.append('acao', 'catalogo_rapido'); fd.append('catalogo_tipo', tipoSel); fd.append('catalogo_nome', nome); fd.append('catalogo_descricao', det);
        fd.append('catalogo_unidade', un); fd.append('catalogo_valor', moneyBR(preco)); fd.append('catalogo_custo', moneyBR(custo));
        fd.append('catalogo_linha', linhaModal || categoria);   // peça de serralheria vai para o catálogo de serralheria; serviço, para o tipo do orçamento
        var est = q('[data-new-estoque]'); fd.append('catalogo_quantidade_estoque', moneyBR(est ? parseBR(est.value) : 0));
        if (fotoEl && fotoEl.files && fotoEl.files[0]) fd.append('catalogo_foto', fotoEl.files[0]);
        fetch('orcamento-novo.php', { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (j) { toast(j && j.ok ? 'Salvo no catálogo.' : ((j && j.msg) || 'Não foi possível salvar no catálogo.'), j && j.ok ? 'ok' : 'err'); })
          .catch(function () { toast('Não foi possível salvar no catálogo (sem conexão).', 'err'); });
      }
      $$('[data-new-nome],[data-new-det],[data-new-preco],[data-new-custo],[data-new-markup],[data-new-estoque]', modal).forEach(function (el) { el.value = ''; });
      if (fotoEl) { fotoEl.value = ''; var nm = $('[data-new-foto-nome]', modal); if (nm) nm.textContent = 'A foto vai para o catálogo'; }
      lucroEl.value = tipoBase !== 'servico' ? '30,00' : '';
      q('[data-new-un]').value = 'un'; q('[data-new-tipo]').selectedIndex = 0; qtdEl.value = '1'; q('[data-new-salvar]').checked = false;
      novoValor(); fechar(modal); atualizarListasAbertas();
    });
    sincronizar();
  });

  /* ------------------------------------------------------------------ telas "peças do pedido" / "serviços do pedido" */
  var GRUPOS = {
    pecas:       { box: boxPeca, modal: 'addPecaModal',          sheet: 'shListaPecas',       serv: false },
    serralheria: { box: boxSerr, modal: 'addSerralheriaModal',   sheet: 'shListaSerralheria', serv: false },
    servicos:    { box: boxServ, modal: 'addServicoModal',       sheet: 'shListaServicos',    serv: true }
  };
  var estados = {};

  function abrirCatalogo(g) {
    var m = document.getElementById(GRUPOS[g].modal);
    if (m && m.__sincronizarSelecao) m.__sincronizarSelecao();
    abrir(m);
  }
  function catalogoDoItem(desc, serv) {
    return (CFG.catalogo || []).filter(function (x) { return ehServico(x.tipo) === serv && nomeComDetalhe(x) === desc; })[0];
  }
  function partesDesc(desc) { var p = String(desc).split(' — '), nome = p.shift(); return { nome: nome, det: p.join(' — ') }; }
  function mostrarGrupo(g) {   // clique na linha: já tem itens -> a tela "do pedido"; vazio -> direto no catálogo
    if ($$('.pd-item', GRUPOS[g].box).length) { renderLista(g); abrir(GRUPOS[g].sheet); } else abrirCatalogo(g);
  }
  $$('[data-grupo-abrir]').forEach(function (b) { b.addEventListener('click', function () { mostrarGrupo(b.getAttribute('data-grupo-abrir')); }); });

  function textoEstoque(g, l) {
    var el = l.estEl; if (!el) return;
    var un = (l.cat && l.cat.unidade) || 'un', txt = '', aviso = false;
    if (l.cat) {
      var est = Number(l.cat.quantidade_estoque) || 0;
      txt = 'Estoque atual: ' + fmtQtd(est) + ' ' + un;
      if (l.d.origem !== 'comprar' && l.d.qtd > est) { txt += ' · faltam ' + fmtQtd(l.d.qtd - est); aviso = true; }
    } else if (l.d.origem === 'comprar') txt = 'Para comprar';
    el.textContent = txt; el.hidden = !txt; el.classList.toggle('warn', aviso);
  }
  function valorLinha(l) { return l.removida ? 0 : Math.max(0, l.d.qtd) * l.d.preco; }
  function rodapeLista(g) {
    var est = estados[g], sh = document.getElementById(GRUPOS[g].sheet);
    $('[data-lista-subtotal]', sh).textContent = 'R$ ' + moneyBR(est.linhas.reduce(function (t, l) { return t + valorLinha(l); }, 0));
    $('[data-lista-salvar]', sh).disabled = !est.alterado;
  }
  function cartaoLista(g, l) {
    var cfg = GRUPOS[g], pt = partesDesc(l.d.desc), un = (l.cat && l.cat.unidade) || 'un';
    var c = document.createElement('div'); c.className = 'pd-lc';
    var top = document.createElement('div'); top.className = 'pd-lc-top';
    if (l.cat && l.cat.foto_url) { var im = document.createElement('img'); im.src = l.cat.foto_url; im.alt = ''; im.loading = 'lazy'; top.appendChild(im); }
    var tx = document.createElement('div'); tx.className = 'tx';
    var nm = document.createElement('strong'); nm.className = 'nm'; nm.textContent = pt.nome; tx.appendChild(nm);
    if (pt.det) { var dt = document.createElement('span'); dt.className = 'dt'; dt.textContent = pt.det; tx.appendChild(dt); }
    top.appendChild(tx); c.appendChild(top);

    var ctl = document.createElement('div'); ctl.className = 'pd-lc-ctl';
    ctl.innerHTML = '<label class="pd-flut"><span class="rs">R$</span><input data-l-preco inputmode="decimal" aria-label="Preço"><small></small></label>' +
      '<div class="pd-step"><button type="button" class="menos" data-l-menos aria-label="Diminuir">−</button><input data-l-qtd inputmode="decimal" aria-label="Quantidade"><button type="button" class="mais" data-l-mais aria-label="Aumentar">＋</button></div>';
    c.appendChild(ctl);
    var precoIn = $('[data-l-preco]', ctl), qtdIn = $('[data-l-qtd]', ctl);
    precoIn.value = moneyBR(l.d.preco); qtdIn.value = fmtQtd(l.d.qtd); $('small', ctl).textContent = 'por ' + un;

    if (!cfg.serv) { l.estEl = document.createElement('p'); l.estEl.className = 'pd-lc-est'; c.appendChild(l.estEl); }
    var ft = document.createElement('div'); ft.className = 'pd-lc-foot';
    ft.innerHTML = '<div class="acts"><button type="button" class="pd-lc-btn bad" data-l-del aria-label="Remover item"><span class="material-symbols-outlined" aria-hidden="true">delete</span></button>' +
      '<button type="button" class="pd-lc-btn" data-l-op aria-label="Mais opções do item (custo, margem, origem)"><span class="material-symbols-outlined" aria-hidden="true">tune</span></button></div>' +
      '<div class="val"><span>Valor</span><strong data-l-valor></strong></div>';
    c.appendChild(ft);
    var valEl = $('[data-l-valor]', ft);

    function atualizar() {
      l.d.preco = parseBR(precoIn.value); l.d.qtd = parseBR(qtdIn.value);
      valEl.textContent = 'R$ ' + moneyBR(valorLinha(l)); textoEstoque(g, l);
      estados[g].alterado = true; rodapeLista(g);
    }
    precoIn.addEventListener('input', atualizar);
    qtdIn.addEventListener('input', atualizar);
    qtdIn.addEventListener('change', function () { if (parseBR(qtdIn.value) <= 0) { qtdIn.value = '1'; atualizar(); } });
    $('[data-l-menos]', ctl).addEventListener('click', function () { qtdIn.value = fmtQtd(Math.max(1, (parseBR(qtdIn.value) || 1) - 1)); atualizar(); });
    $('[data-l-mais]', ctl).addEventListener('click', function () { qtdIn.value = fmtQtd((parseBR(qtdIn.value) || 0) + 1); atualizar(); });
    $('[data-l-del]', ft).addEventListener('click', function () { l.removida = true; c.hidden = true; estados[g].alterado = true; rodapeLista(g); });
    $('[data-l-op]', ft).addEventListener('click', function () { aplicarLista(g, false); editarItem(l.el); });   // custo, margem, origem, descrição: janela de edição completa
    valEl.textContent = 'R$ ' + moneyBR(valorLinha(l)); textoEstoque(g, l);
    return c;
  }
  function renderLista(g) {
    var cfg = GRUPOS[g], sh = document.getElementById(cfg.sheet), cont = $('[data-lista]', sh);
    estados[g] = { linhas: [], alterado: false };
    cont.innerHTML = '';
    $$('.pd-item', cfg.box).forEach(function (el) {
      var d = lerItem(el), l = { el: el, d: d, cat: catalogoDoItem(d.desc, cfg.serv), removida: false };
      estados[g].linhas.push(l); cont.appendChild(cartaoLista(g, l));
    });
    if (!estados[g].linhas.length) cont.innerHTML = '<p class="pd-hint" style="text-align:center;padding:36px 0">Nada por aqui ainda. Toque em adicionar.</p>';
    rodapeLista(g);
  }
  function aplicarLista(g, fecharTela) {
    var est = estados[g];
    if (est && est.alterado) {
      est.linhas.forEach(function (l) {
        if (l.removida) { l.el.remove(); return; }
        campo(l.el, 'item_qtd').value = String(l.d.qtd > 0 ? l.d.qtd : 1);
        campo(l.el, 'item_preco').value = moneyBR(l.d.preco);
        desenharItem(l.el);
      });
      calcular(); marcarAlterado(); est.alterado = false;
    }
    if (fecharTela) fechar(document.getElementById(GRUPOS[g].sheet));
  }
  function voltarLista(g) {
    if (estados[g] && estados[g].alterado) {
      confirmar('Descartar as alterações feitas nestes itens?', { danger: true, okText: 'Descartar' }).then(function (ok) { if (ok) fechar(document.getElementById(GRUPOS[g].sheet)); });
    } else fechar(document.getElementById(GRUPOS[g].sheet));
  }
  Object.keys(GRUPOS).forEach(function (g) {
    var sh = document.getElementById(GRUPOS[g].sheet);
    $('[data-lista-voltar]', sh).addEventListener('click', function () { voltarLista(g); });
    $('[data-lista-salvar]', sh).addEventListener('click', function () { aplicarLista(g, true); });
    $('[data-lista-add]', sh).addEventListener('click', function () { aplicarLista(g, false); abrirCatalogo(g); });
    sh.addEventListener('cancel', function (e) { e.preventDefault(); voltarLista(g); });   // Esc / botão voltar do celular
  });
  function atualizarListasAbertas() {   // a lista aberta (por baixo do catálogo ou da edição) mostra o que mudou
    Object.keys(GRUPOS).forEach(function (g) { if (document.getElementById(GRUPOS[g].sheet).open) renderLista(g); });
  }
  /* voltou do catálogo ou da edição completa: a lista mostra o que mudou */
  document.addEventListener('close', function (e) {
    var id = e.target && e.target.id;
    Object.keys(GRUPOS).forEach(function (g) {
      var sh = document.getElementById(GRUPOS[g].sheet);
      if (sh.open && (id === 'shItem' || id === GRUPOS[g].modal)) renderLista(g);
    });
  }, true);

  /* ------------------------------------------------------------------ calendário (validade) */
  (function () {
    var input = document.getElementById('validadeInput'), chip = document.getElementById('validadeChip'), plus = document.getElementById('validadePlus');
    var grid = document.getElementById('calGrid'), label = document.getElementById('calMes'), lista = document.getElementById('calLista');
    var MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    var view = new Date(), sel = null, COMP = CFG.comp || {};
    if (input.value) { var p = input.value.split('-'); sel = new Date(+p[0], +p[1] - 1, +p[2]); view = new Date(sel); }
    function iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
    function br(d) { return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + '/' + d.getFullYear(); }
    function chipSync() { if (sel) { chip.textContent = br(sel); chip.hidden = false; plus.hidden = true; } else { chip.hidden = true; plus.hidden = false; } }
    function comps() {
      lista.innerHTML = '';
      var l = sel ? (COMP[iso(sel)] || []) : [];
      if (!l.length) { lista.innerHTML = '<li>Nenhum compromisso nesta data.</li>'; return; }
      l.slice(0, 6).forEach(function (n) { var li = document.createElement('li'); li.textContent = '• ' + n; lista.appendChild(li); });
    }
    function desenhar() {
      label.textContent = MESES[view.getMonth()] + ' de ' + view.getFullYear();
      grid.innerHTML = '';
      ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'].forEach(function (d) { var s = document.createElement('span'); s.className = 'dow'; s.textContent = d; grid.appendChild(s); });
      var primeiro = new Date(view.getFullYear(), view.getMonth(), 1), dias = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
      for (var i = 0; i < primeiro.getDay(); i++) grid.appendChild(document.createElement('span'));
      for (var d = 1; d <= dias; d++) {
        (function (d) {
          var dt = new Date(view.getFullYear(), view.getMonth(), d), b = document.createElement('button'), key = iso(dt);
          b.type = 'button'; b.className = 'pd-cal-dia'; b.textContent = d;
          if (COMP[key]) { b.classList.add('tem'); b.appendChild(document.createElement('b')); }
          if (sel && iso(sel) === key) b.classList.add('sel');
          b.addEventListener('click', function () { sel = dt; desenhar(); });
          grid.appendChild(b);
        })(d);
      }
      comps();
    }
    $$('[data-abrir="shCal"]').forEach(function (el) { el.addEventListener('click', desenhar); });
    document.getElementById('calPrev').addEventListener('click', function () { view.setMonth(view.getMonth() - 1); desenhar(); });
    document.getElementById('calNext').addEventListener('click', function () { view.setMonth(view.getMonth() + 1); desenhar(); });
    document.getElementById('calLimpar').addEventListener('click', function () { sel = null; input.value = ''; chipSync(); desenhar(); marcarAlterado(); });
    document.getElementById('calOk').addEventListener('click', function () { if (sel) input.value = iso(sel); chipSync(); marcarAlterado(); fechar(document.getElementById('shCal')); });
    chipSync();
  })();

  /* ------------------------------------------------------------------ cliente e endereço */
  var selCli = $('[data-cliente-select]'), selEnd = $('[data-endereco-select]'), endFinal = $('[data-endereco-final]'), endLivre = document.getElementById('enderecoLivre');
  var cliAnterior = selCli.value;
  function filtraEnderecos() {
    $$('option', selEnd).forEach(function (op) {
      if (op.value === '0' || op.value === '-1') { op.hidden = false; return; }
      op.hidden = !!op.getAttribute('data-cliente-id') && op.getAttribute('data-cliente-id') !== selCli.value;
    });
  }
  function usarEndereco() {
    var op = selEnd.options[selEnd.selectedIndex];
    var outro = selEnd.value === '-1';
    endLivre.hidden = !outro;
    if (outro) { endFinal.value = endLivre.value.trim(); }
    else if (op && op.getAttribute('data-end')) endFinal.value = op.getAttribute('data-end');
  }
  function usarCliente(preservaEndereco) {
    var o = selCli.options[selCli.selectedIndex];
    if (!o || selCli.value === '0' || selCli.value === '-1') return;
    $('[data-cliente-nome]').value = o.getAttribute('data-nome') || '';
    $('[data-cliente-tel]').value = o.getAttribute('data-tel') || '';
    $('[data-cliente-email]').value = o.getAttribute('data-email') || '';
    filtraEnderecos();
    if (!preservaEndereco) { if (endFinal.value === '' || selEnd.value === '0') endFinal.value = o.getAttribute('data-end') || endFinal.value; }
  }
  selCli.addEventListener('change', function () {
    marcarAlterado();
    if (selCli.value === '-1') { selCli.value = cliAnterior; abrir('shCliente'); setTimeout(function () { $('#ncNome').focus(); }, 60); return; }
    cliAnterior = selCli.value;
    selEnd.value = '0'; endFinal.value = ''; endLivre.hidden = true;
    usarCliente(false); usarEndereco();
  });
  selEnd.addEventListener('change', function () { marcarAlterado(); usarEndereco(); });
  endLivre.addEventListener('input', function () { endFinal.value = endLivre.value.trim(); marcarAlterado(); });
  (function iniciaCliente() {
    usarCliente(!!CFG.editando);
    if (CFG.editando && CFG.enderecos) {
      var achou = false;
      $$('option', selEnd).forEach(function (op) { if (!achou && op.getAttribute('data-end') === CFG.enderecos && (!op.getAttribute('data-cliente-id') || op.getAttribute('data-cliente-id') === selCli.value)) { selEnd.value = op.value; achou = true; } });
      if (!achou) { selEnd.value = '-1'; endLivre.value = CFG.enderecos; endLivre.hidden = false; }
      endFinal.value = CFG.enderecos;
    } else { usarEndereco(); }
  })();

  $('#ncSalvar').addEventListener('click', function () {
    var nome = $('#ncNome').value.trim();
    if (!nome) { toast('Informe o nome do cliente.', 'warn'); $('#ncNome').focus(); return; }
    var btn = this; btn.disabled = true;
    var fd = new FormData();
    fd.append('acao', 'cliente_rapido'); fd.append('nome', nome); fd.append('telefone', $('#ncTel').value.trim()); fd.append('email', $('#ncMail').value.trim());
    fetch('orcamento-novo.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) { toast((j && j.msg) || 'Não foi possível cadastrar o cliente.', 'err'); return; }
        var op = $('option[value="' + j.id + '"]', selCli);
        if (!op) {
          op = document.createElement('option'); op.value = String(j.id); op.textContent = j.nome;
          op.setAttribute('data-nome', j.nome); op.setAttribute('data-tel', j.telefone || ''); op.setAttribute('data-email', j.email || ''); op.setAttribute('data-end', '');
          selCli.insertBefore(op, selCli.querySelector('option[value="-1"]'));
        }
        selCli.value = String(j.id); cliAnterior = selCli.value;
        selEnd.value = '0'; endFinal.value = ''; usarCliente(false); marcarAlterado();
        $('#ncNome').value = ''; $('#ncTel').value = ''; $('#ncMail').value = '';
        fechar(document.getElementById('shCliente'));
        toast(j.existente ? 'Esse cliente já existia: selecionado.' : 'Cliente cadastrado.', 'ok');
      })
      .catch(function () { toast('Sem conexão com o servidor.', 'err'); })
      .then(function () { btn.disabled = false; });
  });

  /* ------------------------------------------------------------------ pagamento, cláusulas */
  function meiosResumo() {
    var v = $$('input[name="meios_pagamento[]"]:checked').map(function (c) { return c.value; });
    $('#meiosResumo').textContent = v.length ? v.join(', ') : 'Escolha como o cliente pode pagar';
  }
  $$('input[name="meios_pagamento[]"]').forEach(function (c) { c.addEventListener('change', meiosResumo); });
  var claTxt = document.getElementById('clausulasTxt');
  function claResumo() { var t = claTxt.value.replace(/\s+/g, ' ').trim(); $('#claResumo').textContent = t ? t.slice(0, 80) : 'Toque para escrever ou usar o modelo'; }
  claTxt.addEventListener('input', claResumo);
  $('[data-cla-modelo]').addEventListener('click', function () {
    function usar() { claTxt.value = CFG.modelo || ''; claResumo(); marcarAlterado(); }
    if (claTxt.value.trim() === '') usar();
    else confirmar('Trocar o texto atual pelo modelo do sistema?', { okText: 'Usar o modelo' }).then(function (ok) { if (ok) usar(); });
  });

  /* ------------------------------------------------------------------ fotos */
  var fotosGrid = document.getElementById('fotosGrid'), fotosPicker = document.getElementById('fotosInput');
  var fotosReal = document.createElement('input');
  fotosReal.type = 'file'; fotosReal.name = 'fotos[]'; fotosReal.multiple = true; fotosReal.hidden = true; form.appendChild(fotosReal);
  var novas = [], MAX_FOTOS = 12;

  function reduzir(file) {
    return new Promise(function (resolve) {
      if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { resolve(file); return; }
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () {
        var w = img.naturalWidth, h = img.naturalHeight, k = Math.min(1, 1920 / Math.max(w, h));
        if (k === 1 && file.size < 1500000) { URL.revokeObjectURL(url); resolve(file); return; }
        var c = document.createElement('canvas'); c.width = Math.round(w * k); c.height = Math.round(h * k);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        c.toBlob(function (b) {
          URL.revokeObjectURL(url);
          if (!b || b.size >= file.size) { resolve(file); return; }
          resolve(new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }));
        }, 'image/jpeg', 0.82);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }
  function totalFotos() { return $$('.pd-foto:not(.removida)', fotosGrid).length; }
  function resumoFotos() {
    var n = totalFotos();
    $('#fotosResumo').textContent = n ? n + (n === 1 ? ' foto no pedido' : ' fotos no pedido') : 'As fotos aparecem no documento';
  }
  function sincronizarFotos() {
    var dt = new DataTransfer();
    novas.forEach(function (n) { dt.items.add(n.file); });
    fotosReal.files = dt.files;
    resumoFotos();
  }
  document.getElementById('fotosAdd').addEventListener('click', function () { fotosPicker.click(); });
  fotosPicker.addEventListener('change', function () {
    var arquivos = Array.prototype.slice.call(fotosPicker.files || []);
    fotosPicker.value = '';
    var vagas = MAX_FOTOS - totalFotos();
    if (arquivos.length > vagas) { toast('Cada pedido aceita até ' + MAX_FOTOS + ' fotos.', 'warn'); arquivos = arquivos.slice(0, Math.max(0, vagas)); }
    arquivos.reduce(function (p, f) {
      return p.then(function () { return reduzir(f); }).then(function (r) {
        var url = URL.createObjectURL(r), el = document.createElement('div');
        el.className = 'pd-foto';
        el.innerHTML = '<img alt="Foto nova do pedido"><button type="button" aria-label="Remover foto"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>';
        $('img', el).src = url;
        var reg = { file: r, el: el, url: url };
        $('button', el).addEventListener('click', function () { novas = novas.filter(function (x) { return x !== reg; }); URL.revokeObjectURL(url); el.remove(); sincronizarFotos(); marcarAlterado(); });
        novas.push(reg); fotosGrid.appendChild(el);
      });
    }, Promise.resolve()).then(function () { sincronizarFotos(); marcarAlterado(); });
  });
  fotosGrid.addEventListener('click', function (e) {
    var b = e.target.closest('[data-foto-remover]');
    if (!b) return;
    var el = b.closest('.pd-foto'), id = el.getAttribute('data-foto-id');
    var marcado = el.classList.toggle('removida');
    var oculto = $('input[name="foto_remover[]"][value="' + id + '"]', form);
    if (marcado && !oculto) { var i = document.createElement('input'); i.type = 'hidden'; i.name = 'foto_remover[]'; i.value = id; form.appendChild(i); }
    if (!marcado && oculto) oculto.remove();
    resumoFotos(); marcarAlterado();
  });

  /* ------------------------------------------------------------------ sair, salvar */
  document.addEventListener('click', function (e) {
    var s = e.target.closest('[data-sair]');
    if (!s || !dirty) return;
    e.preventDefault();
    confirmar('Sair sem salvar? As alterações deste pedido serão perdidas.', { danger: true, okText: 'Sair' }).then(function (ok) { if (ok) { dirty = false; window.location.href = s.getAttribute('href'); } });
  });
  form.addEventListener('input', marcarAlterado);
  form.addEventListener('change', marcarAlterado);
  form.addEventListener('keydown', function (e) {   // Enter num campo não deve salvar o pedido sem querer
    if (e.key === 'Enter' && e.target.tagName === 'INPUT' && !/^(submit|button|checkbox|radio)$/.test(e.target.type)) e.preventDefault();
  });
  $('#btnSalvar').addEventListener('click', function () { document.getElementById('acaoInput').value = 'salvar'; });
  $('#btnPdf').addEventListener('click', function () { document.getElementById('acaoInput').value = 'salvar_pdf'; });
  form.addEventListener('submit', function (e) {
    if (!selCli.value || selCli.value === '0' || selCli.value === '-1') {
      e.preventDefault(); toast('Selecione ou adicione um cliente.', 'warn');
      selCli.scrollIntoView({ behavior: 'smooth', block: 'center' }); selCli.focus(); return;
    }
    if (selEnd.value === '-1') endFinal.value = endLivre.value.trim();
    dirty = false;
    $$('.pd-save,.pd-pdf').forEach(function (b) { setTimeout(function () { b.disabled = true; }, 0); });
    $('#btnSalvar').textContent = 'salvando…';
  });
  window.addEventListener('pageshow', function () { $$('.pd-save,.pd-pdf').forEach(function (b) { b.disabled = false; }); $('#btnSalvar').textContent = 'salvar pedido'; });

  /* ------------------------------------------------------------------ tipo do orçamento: CFTV | Serralheria */
  var inputCat = document.getElementById('categoriaInput');
  function aplicarCategoria(nova, marcar) {
    categoria = nova === 'serralheria' ? 'serralheria' : 'cftv';
    if (inputCat) inputCat.value = categoria;
    $$('[data-categoria]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-categoria') === categoria ? 'true' : 'false'); });
    // a linha de peças do outro tipo some (a não ser que já tenha itens: nada fica escondido com dado dentro)
    $$('[data-linha-row]').forEach(function (el) {
      var linha = el.getAttribute('data-linha-row'), box = el.classList.contains('pd-items') ? el : document.getElementById(linha === 'serralheria' ? 'itemsSerralheria' : 'itemsPecas');
      var temItem = !!box && $$('.pd-item', box).length > 0;
      el.hidden = linha !== categoria && !temItem;
    });
    // serviços: cada cartão do catálogo é de uma linha (ou geral); mostra os do tipo escolhido + os gerais
    $$('#addServicoModal [data-sel]').forEach(function (c) {
      var l = c.getAttribute('data-linha-item') || 'geral';
      c.setAttribute('data-fora-linha', (l !== 'geral' && l !== categoria) ? '1' : '0');
    });
    var busca = $('#addServicoModal [data-add-busca]');
    if (busca) busca.dispatchEvent(new Event('input', { bubbles: true }));
    if (marcar) marcarAlterado();
  }
  $$('[data-categoria]').forEach(function (b) { b.addEventListener('click', function () { aplicarCategoria(b.getAttribute('data-categoria'), true); }); });

  /* ------------------------------------------------------------------ início */
  todosItens().forEach(desenharItem);
  calcular();
  aplicarCategoria(categoria, false);
  dirty = false;
})();
