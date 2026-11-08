/* =========================================================================
   VN System — A Pagar (contas-pagar.php) · v1
   - janelas: nova/editar conta, pagar, fotos e contas fixas (usa <dialog>)
   - repetir: escolher "Todo mês" etc. na conta nova; editar/parar numa conta que se repete
   - campo de valor com máscara (1.250,00)
   - fotos: pré-visualização, redução no celular antes de enviar e galeria com ampliação
   Sem JS os formulários ainda enviam normalmente; o JS só deixa tudo mais leve.
   ========================================================================= */
(function () {
  'use strict';

  var root = document.getElementById('cpRoot');
  if (!root) return;

  var $ = function (sel, ctx) { return (ctx || root).querySelector(sel); };
  var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); };
  var toast = function (msg, tipo) { if (window.VN && VN.toast) VN.toast(msg, tipo); else window.alert(msg); };

  var TIPOS = { conta: 'Conta / boleto', comprovante: 'Comprovante de pagamento' };
  var FORMAS = { pix: 'PIX', boleto: 'Boleto', transferencia: 'Transferência', dinheiro: 'Dinheiro', cartao: 'Cartão', debito: 'Débito automático', outro: 'Outro' };

  /* ------------------------------------------------------------ utilidades */
  function dadosDaLinha(el) {
    var tr = el.closest('[data-cp]');
    if (!tr) return null;
    try { return JSON.parse(tr.getAttribute('data-cp')); } catch (e) { return null; }
  }
  function abrir(dlg) { if (typeof dlg.showModal === 'function') { if (!dlg.open) dlg.showModal(); } else { dlg.setAttribute('open', ''); } }
  function fechar(dlg) { if (dlg.open) { try { dlg.close(); } catch (e) { dlg.removeAttribute('open'); } } }
  function setVal(form, nome, v) { var el = form.elements[nome]; if (el) el.value = v == null ? '' : v; }
  function limparArquivos(form) {
    $$('input[type="file"]', form).forEach(function (i) { i.value = ''; });
    $$('[data-cp-previews]', form).forEach(function (p) { revogar(p); p.innerHTML = ''; });
  }
  function urlFoto(id, mini) { return 'contas-pagar-foto.php?id=' + encodeURIComponent(id) + (mini ? '&mini=1' : ''); }
  function dinheiro(txt) { return 'R$ ' + txt; }
  function dataBR(iso) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || ''); return m ? m[3] + '/' + m[2] + '/' + m[1] : ''; }

  /* ------------------------------------------------------------ máscara de valor: digita 125000 -> 1.250,00 */
  function mascara(el) {
    var d = el.value.replace(/\D/g, '').replace(/^0+/, '');
    if (!d) { el.value = ''; return; }
    while (d.length < 3) d = '0' + d;
    el.value = d.slice(0, -2).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + d.slice(-2);
  }
  root.addEventListener('input', function (e) { if (e.target.matches && e.target.matches('[data-cp-money]')) mascara(e.target); });

  /* ------------------------------------------------------------ janela: nova / editar conta */
  var dlgConta = $('#cpDlgConta');
  var formConta = $('form[data-cp-form]', dlgConta);

  function abrirConta(c) {
    var nova = !c;
    limparArquivos(formConta);
    $('[data-cp-titulo]', dlgConta).textContent = nova ? 'Nova conta' : 'Editar conta';
    setVal(formConta, 'conta_id', nova ? 0 : c.id);
    setVal(formConta, 'descricao', nova ? '' : c.descricao);
    setVal(formConta, 'valor', nova ? '' : c.valor);
    setVal(formConta, 'vencimento', nova ? '' : c.vencimento);
    setVal(formConta, 'fornecedor', nova ? '' : c.fornecedor);
    setVal(formConta, 'categoria', nova ? '' : c.categoria);
    setVal(formConta, 'codigo', nova ? '' : c.codigo);
    setVal(formConta, 'observacao', nova ? '' : c.observacao);
    // repetir e fotos: só na conta nova (fotos de uma conta existente: pela janela "Fotos")
    Array.prototype.forEach.call(dlgConta.querySelectorAll('[data-cp-so-nova]'), function (e) { e.hidden = !nova; });
    setVal(formConta, 'repetir', '');
    setVal(formConta, 'vezes', '');
    atualizarVezes();
    // conta que já se repete: caixa com "aplicar às próximas" e "parar de repetir"
    var caixa = $('[data-cp-rec-box]', dlgConta);
    caixa.hidden = nova || !c.rec;
    if (!caixa.hidden) {
      $('[data-cp-rec-texto]', caixa).textContent = c.rec.ativa
        ? 'Esta conta se repete: ' + c.rec.rotulo + '. As próximas aparecem sozinhas.'
        : 'Esta conta fazia parte de uma repetição que já terminou: ' + c.rec.rotulo + '.';
      $('[data-cp-parar]', caixa).hidden = !c.rec.ativa;
      var chk = formConta.elements.aplicar_proximas;
      if (chk) { chk.checked = false; chk.closest('label').hidden = !c.rec.ativa; }
    }
    var fp = $('#cpFormParar', dlgConta);
    if (fp) fp.elements.conta_id.value = nova ? 0 : c.id;
    var ex = $('[data-cp-excluir]', dlgConta);
    ex.hidden = nova;
    var fe = $('#cpFormExcluir', dlgConta);
    if (fe) fe.elements.conta_id.value = nova ? 0 : c.id;
    abrir(dlgConta);
  }
  /* "Quantas vezes?" só aparece quando a conta vai se repetir */
  function atualizarVezes() {
    var sel = formConta.elements.repetir;
    var campo = $('[data-cp-vezes]', dlgConta);
    if (campo) campo.hidden = dlgConta.querySelector('[data-cp-so-nova]').hidden || !sel || sel.value === '';
  }
  formConta.elements.repetir.addEventListener('change', atualizarVezes);

  $('[data-cp-parar]', dlgConta).addEventListener('click', function () {
    var fp = $('#cpFormParar', dlgConta);
    if (fp && fp.requestSubmit) fp.requestSubmit(); else if (fp) fp.submit();
  });

  $('[data-cp-excluir]', dlgConta).addEventListener('click', function () {
    var fe = $('#cpFormExcluir', dlgConta);
    if (fe && fe.requestSubmit) fe.requestSubmit(); else if (fe) fe.submit();
  });

  /* ------------------------------------------------------------ janela: pagar */
  var dlgPagar = $('#cpDlgPagar');
  var formPagar = $('form[data-cp-form]', dlgPagar);

  function abrirPagar(c) {
    limparArquivos(formPagar);
    setVal(formPagar, 'conta_id', c.id);
    setVal(formPagar, 'pago_em', root.getAttribute('data-hoje') || '');
    setVal(formPagar, 'valor_pago', c.valor);
    setVal(formPagar, 'forma_pagamento', '');
    $('[data-cp-resumo]', dlgPagar).textContent = c.descricao + ' · ' + dinheiro(c.valor) + ' · vence ' + dataBR(c.vencimento);
    abrir(dlgPagar);
  }

  /* ------------------------------------------------------------ janela: fotos */
  var dlgFotos = $('#cpDlgFotos');
  var formFotos = $('form[data-cp-form]', dlgFotos);
  var galeria = $('[data-cp-galeria]', dlgFotos);
  var contaFotos = null;

  function formDeletar(foto) {
    var f = document.createElement('form');
    f.method = 'post';
    f.setAttribute('data-danger', '');
    f.setAttribute('data-confirm', 'Excluir esta foto? Isso não pode ser desfeito.');
    [['acao', 'del_foto'], ['foto_id', foto.id], ['ver', root.getAttribute('data-ver') || ''], ['q', root.getAttribute('data-q') || '']].forEach(function (par) {
      var i = document.createElement('input'); i.type = 'hidden'; i.name = par[0]; i.value = par[1]; f.appendChild(i);
    });
    var b = document.createElement('button');
    b.type = 'submit'; b.setAttribute('aria-label', 'Excluir foto'); b.title = 'Excluir foto';
    b.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">delete</span>';
    f.appendChild(b);
    return f;
  }

  function desenharGaleria() {
    galeria.innerHTML = '';
    var fotos = (contaFotos && contaFotos.fotos) || [];
    if (!fotos.length) {
      var vazio = document.createElement('div');
      vazio.className = 'cp-sem-fotos';
      vazio.textContent = 'Nenhuma foto ainda. Tire uma foto ou escolha da galeria abaixo.';
      galeria.appendChild(vazio);
      return;
    }
    Object.keys(TIPOS).forEach(function (tipo) {
      var doTipo = fotos.filter(function (f) { return f.tipo === tipo; });
      if (!doTipo.length) return;
      var grupo = document.createElement('div'); grupo.className = 'cp-grupo';
      var h = document.createElement('h5'); h.textContent = TIPOS[tipo] + ' (' + doTipo.length + ')'; grupo.appendChild(h);
      var grade = document.createElement('div'); grade.className = 'cp-fotos';
      doTipo.forEach(function (foto) {
        var box = document.createElement('div'); box.className = 'cp-foto';
        var abrirBtn = document.createElement('button');
        abrirBtn.type = 'button'; abrirBtn.className = 'cp-abrir';
        abrirBtn.setAttribute('aria-label', 'Ampliar foto ' + (foto.nome || ''));
        var img = document.createElement('img'); img.loading = 'lazy'; img.alt = foto.nome || 'Foto'; img.src = urlFoto(foto.id, true);
        abrirBtn.appendChild(img);
        abrirBtn.addEventListener('click', function () { abrirViewer(fotos, fotos.indexOf(foto)); });
        box.appendChild(abrirBtn);
        var f = formDeletar(foto); f.appendChild(Object.assign(document.createElement('input'), { type: 'hidden', name: 'conta_id', value: contaFotos.id }));
        box.appendChild(f);
        grade.appendChild(box);
      });
      grupo.appendChild(grade);
      galeria.appendChild(grupo);
    });
  }

  function abrirFotos(c) {
    contaFotos = c;
    limparArquivos(formFotos);
    setVal(formFotos, 'conta_id', c.id);
    setVal(formFotos, 'tipo', c.pago ? 'comprovante' : 'conta');
    $('#cpFotosTitulo', dlgFotos).textContent = 'Fotos — ' + c.descricao;
    $('[data-cp-resumo]', dlgFotos).textContent = dinheiro(c.valor) + (c.pago ? ' · paga em ' + dataBR(c.pago_em) + (c.forma && FORMAS[c.forma] ? ' (' + FORMAS[c.forma] + ')' : '') : ' · vence ' + dataBR(c.vencimento));
    desenharGaleria();
    abrir(dlgFotos);
  }

  /* ------------------------------------------------------------ foto ampliada */
  var viewer = $('#cpViewer');
  var vImg = $('[data-cp-img]', viewer);
  var vLista = [], vPos = 0;

  function mostrarFoto() {
    var f = vLista[vPos];
    if (!f) return;
    vImg.src = urlFoto(f.id, false);
    vImg.alt = f.nome || 'Foto da conta';
    $('[data-cp-legenda]', viewer).textContent = (TIPOS[f.tipo] || 'Foto') + ' · ' + (vPos + 1) + ' de ' + vLista.length + (f.nome ? ' · ' + f.nome : '');
    $('[data-cp-original]', viewer).href = urlFoto(f.id, false);
    var varias = vLista.length > 1;
    $('[data-cp-anterior]', viewer).hidden = !varias;
    $('[data-cp-proxima]', viewer).hidden = !varias;
  }
  function abrirViewer(lista, pos) { vLista = lista; vPos = Math.max(0, pos); mostrarFoto(); abrir(viewer); }
  function passar(delta) { if (vLista.length > 1) { vPos = (vPos + delta + vLista.length) % vLista.length; mostrarFoto(); } }
  $('[data-cp-anterior]', viewer).addEventListener('click', function () { passar(-1); });
  $('[data-cp-proxima]', viewer).addEventListener('click', function () { passar(1); });
  viewer.addEventListener('keydown', function (e) { if (e.key === 'ArrowLeft') passar(-1); else if (e.key === 'ArrowRight') passar(1); });
  var toqueX = null;
  viewer.addEventListener('touchstart', function (e) { toqueX = e.touches.length === 1 ? e.touches[0].clientX : null; }, { passive: true });
  viewer.addEventListener('touchend', function (e) {
    if (toqueX === null || !e.changedTouches.length) return;
    var dx = e.changedTouches[0].clientX - toqueX;
    toqueX = null;
    if (Math.abs(dx) > 60) passar(dx < 0 ? 1 : -1);
  }, { passive: true });
  viewer.addEventListener('close', function () { vImg.removeAttribute('src'); });

  /* ------------------------------------------------------------ cliques gerais */
  root.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target : e.target.parentElement;
    if (!t) return;
    if (t.closest('[data-cp-nova]')) { abrirConta(null); return; }
    var ab = t.closest('[data-cp-abrir]');
    if (ab) { var alvo = document.getElementById(ab.getAttribute('data-cp-abrir')); if (alvo) abrir(alvo); return; }
    var ac = t.closest('[data-cp-acao]');
    if (ac) {
      var c = dadosDaLinha(ac);
      if (!c) return;
      var a = ac.getAttribute('data-cp-acao');
      if (a === 'editar') abrirConta(c);
      else if (a === 'pagar') abrirPagar(c);
      else if (a === 'fotos') abrirFotos(c);
      return;
    }
    var fe = t.closest('[data-cp-fechar]');
    if (fe) { var d = fe.closest('dialog'); if (d) fechar(d); return; }
    // clicar fora da janela (no fundo escuro) fecha
    if (t.tagName === 'DIALOG' && t.classList.contains('cp-dlg')) fechar(t);
  });

  /* ------------------------------------------------------------ fotos: pré-visualização */
  function revogar(box) { $$('img', box).forEach(function (i) { try { URL.revokeObjectURL(i.src); } catch (e) {} }); }
  root.addEventListener('change', function (e) {
    var inp = e.target;
    if (!inp.matches || !inp.matches('input[data-cp-fotos]')) return;
    var box = inp.parentElement.querySelector('[data-cp-previews]') || inp.closest('form').querySelector('[data-cp-previews]');
    if (!box) return;
    revogar(box);
    box.innerHTML = '';
    Array.prototype.slice.call(inp.files || []).slice(0, 20).forEach(function (f) {
      if (!/^image\//.test(f.type)) return;
      var img = document.createElement('img');
      img.alt = f.name; img.src = URL.createObjectURL(f);
      box.appendChild(img);
    });
  });

  /* ------------------------------------------------------------ fotos: reduz antes de enviar (celular tira foto de 4-12 MB) */
  function reduzir(file) {
    return new Promise(function (resolve) {
      if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 500 * 1024 || !window.HTMLCanvasElement) { resolve(file); return; }
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        try {
          var maxLado = 1800, w = img.naturalWidth, h = img.naturalHeight;
          var esc = Math.min(1, maxLado / Math.max(w, h));
          var c = document.createElement('canvas');
          c.width = Math.max(1, Math.round(w * esc)); c.height = Math.max(1, Math.round(h * esc));
          var ctx = c.getContext('2d');
          ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);   // PNG/WebP transparente vira fundo branco
          ctx.drawImage(img, 0, 0, c.width, c.height);
          c.toBlob(function (blob) {
            URL.revokeObjectURL(url);
            if (!blob || blob.size >= file.size) { resolve(file); return; }
            resolve({ blob: blob, name: (file.name || 'foto').replace(/\.[^.]+$/, '') + '.jpg' });
          }, 'image/jpeg', 0.85);
        } catch (err) { URL.revokeObjectURL(url); resolve(file); }
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  /* ------------------------------------------------------------ envio dos formulários (sem recarregar a cada foto) */
  root.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.hasAttribute || !form.hasAttribute('data-cp-form') || !window.fetch || !window.FormData || !window.Promise) return;   // sem suporte: envio normal
    e.preventDefault();
    if (form.__cpBusy) return;
    form.__cpBusy = true;

    var btn = $('[data-cp-enviar]', form);
    var rotulo = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Enviando…'; }
    var livre = function () { form.__cpBusy = false; if (btn) { btn.disabled = false; btn.innerHTML = rotulo; } };

    var fd = new FormData(form);
    var inputs = $$('input[type="file"]', form);
    var arquivos = [];
    inputs.forEach(function (i) { fd.delete(i.name); arquivos = arquivos.concat(Array.prototype.slice.call(i.files || [])); });

    Promise.all(arquivos.map(reduzir)).then(function (prontos) {
      prontos.forEach(function (p) {
        if (p && p.blob) fd.append('fotos[]', p.blob, p.name); else fd.append('fotos[]', p, p.name);
      });
      return fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    }).then(function (r) {
      return r.json().catch(function () { throw new Error('O servidor não aceitou o envio (' + r.status + '). Tente com menos fotos.'); });
    }).then(function (j) {
      if (j && j.redirect) { window.location.href = j.redirect; return; }
      throw new Error((j && (j.erro || j.msg)) || 'Não foi possível salvar. Tente de novo.');
    }).catch(function (err) {
      livre();
      toast(err && err.message ? err.message : 'Falha de conexão. Confira a internet e tente de novo.', 'err');
    });
  });

  /* ------------------------------------------------------------ ao voltar de um envio, reabre as fotos da conta */
  var reabrir = parseInt(root.getAttribute('data-abrir-fotos') || '0', 10);
  if (reabrir > 0) {
    var linha = $$('[data-cp]').map(function (tr) { try { return JSON.parse(tr.getAttribute('data-cp')); } catch (e) { return null; } })
      .filter(function (c) { return c && c.id === reabrir; })[0];
    if (linha) abrirFotos(linha);
  }
})();
