/* =========================================================================
   VN System — Preços da serralheria (serralheria-precos.php)
   Abre as janelas de edição na própria tela (estrutura nova/editar, um valor, reajuste) e evita envio duplo.
   O envio é o formulário normal (POST) e a página volta no mesmo trecho com um aviso.
   ========================================================================= */
(function () {
  'use strict';

  var root = document.getElementById('spRoot');
  if (!root) return;

  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function abrir(d) { if (d && !d.open) { if (d.showModal) d.showModal(); else d.setAttribute('open', 'open'); } }
  function fechar(d) { if (d && d.open) d.close(); }
  function lerJson(el, attr) { try { return JSON.parse(el.getAttribute(attr) || '{}'); } catch (e) { return {}; } }
  function foco(inp) { if (inp && !window.matchMedia('(max-width:900px)').matches) { inp.focus(); if (inp.select) inp.select(); } }

  /* fechar: botão X / Cancelar, ou toque no fundo escuro (só computador) */
  document.addEventListener('click', function (e) {
    var f = e.target.closest('[data-sp-fechar]');
    if (f) { fechar(f.closest('dialog')); return; }
    if (e.target.matches && e.target.matches('dialog.sp-sheet')) fechar(e.target);
  });

  /* ------------------------------------------------------------------ estrutura (nova / editar) */
  var dEst = document.getElementById('spEstrutura'), fEst = document.getElementById('spEstForm'), fDel = document.getElementById('spEstDel');
  function preencherEstrutura(d) {
    var novo = !d.id;
    $('#spEstT').textContent = novo ? 'Nova estrutura' : 'Editar estrutura';
    $('[name=id]', fEst).value = novo ? '0' : String(d.id);
    $('[name=nome]', fEst).value = d.nome || '';
    $('[name=categoria]', fEst).value = novo ? (d.categoria || '') : (d.categoria || '');
    $('[name=unidade]', fEst).value = d.unidade || 'm2';
    $('[name=preco]', fEst).value = d.preco && d.preco !== '0,00' ? d.preco : '';
    $('[name=mao_obra]', fEst).value = d.mao_obra || '';
    fDel.hidden = novo;
    if (!novo) $('[name=id]', fDel).value = String(d.id);
    abrir(dEst); foco($('[name=nome]', fEst));
  }
  $$('[data-sp-estrutura]').forEach(function (b) { b.addEventListener('click', function () { preencherEstrutura(lerJson(b, 'data-sp-estrutura')); }); });
  $$('[data-sp-nova]').forEach(function (b) { b.addEventListener('click', function () { preencherEstrutura({}); }); });

  /* ------------------------------------------------------------------ um valor (mão de obra em R$, nível ou perfil em %) */
  var dVal = document.getElementById('spValor'), fVal = document.getElementById('spValForm');
  $$('[data-sp-valor]').forEach(function (b) {
    b.addEventListener('click', function () {
      var d = lerJson(b, 'data-sp-valor'), pct = d.tipo === 'pct';
      $('#spValT').textContent = 'Editar: ' + (d.rotulo || 'valor');
      $('[name=chave]', fVal).value = d.chave || '';
      $('[data-sp-val-rot]', fVal).textContent = pct ? 'Ajuste (%)' : 'Valor (R$)';
      $('[data-sp-pre]', fVal).hidden = pct; $('[data-sp-pos]', fVal).hidden = !pct;
      var inp = $('[name=valor]', fVal);
      inp.value = d.valor || ''; inp.placeholder = pct ? 'Ex.: 20 ou -10' : '0,00';
      $('[data-sp-val-ajuda]', fVal).textContent = d.ajuda || '';
      abrir(dVal); foco(inp);
    });
  });

  /* ------------------------------------------------------------------ reajuste geral */
  var dRea = document.getElementById('spReajuste');
  $$('[data-sp-reajuste]').forEach(function (b) { b.addEventListener('click', function () { $('#spPct').value = ''; abrir(dRea); foco($('#spPct')); }); });

  /* ------------------------------------------------------------------ envio: um toque só */
  $$('form[method="post"]', root).forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;
      var id = f.id, extra = id ? $$('button[form="' + id + '"]') : [];
      $$('button[type="submit"]', f).concat(extra).forEach(function (b) { setTimeout(function () { b.setAttribute('aria-busy', 'true'); b.disabled = true; }, 0); });
    });
  });
  window.addEventListener('pageshow', function () { $$('.sp-btn[aria-busy]', root).forEach(function (b) { b.removeAttribute('aria-busy'); b.disabled = false; }); });
})();
