/* =========================================================================
   VN System — A Pagar (contas-pagar.php) · v1
   - janelas: nova/editar conta, pagar, fotos e contas fixas (usa <dialog>)
   - repetir: escolher "Todo mês" etc. na conta nova; editar/parar numa conta que se repete
   - campo de valor com máscara (1.250,00)
   - fotos e PDFs: pré-visualização, redução das fotos no celular antes de enviar e galeria com ampliação
   - quem vê a conta: pública (todos os administradores) ou privada (só o dono); a última escolha fica lembrada
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
  function urlFoto(id, mini, baixar) { return 'contas-pagar-foto.php?id=' + encodeURIComponent(id) + (mini ? '&mini=1' : '') + (baixar ? '&baixar=1' : ''); }
  function ehPdf(f) { return !!f && f.formato === 'pdf'; }
  function tamanhoTxt(b) { b = +b || 0; return b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
  /* Última escolha de "quem vê" (só neste aparelho): quem cadastra sempre pública não precisa trocar toda vez. */
  function visLembrada() { try { var v = window.localStorage.getItem('vn.cp.vis'); return v === 'publico' ? 'publico' : 'privado'; } catch (e) { return 'privado'; } }
  function visGuardar(v) { try { window.localStorage.setItem('vn.cp.vis', v === 'publico' ? 'publico' : 'privado'); } catch (e) {} }
  function dinheiro(txt) { return 'R$ ' + txt; }
  function dataBR(iso) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || ''); return m ? m[3] + '/' + m[2] + '/' + m[1] : ''; }
  /* Código único de cada abertura de janela: se o envio for repetido (toque duplo, falha de rede), o servidor recusa o 2º. */
  function novoToken() {
    var a = new Uint8Array(12), i;
    try { (window.crypto || window.msCrypto).getRandomValues(a); } catch (e) { for (i = 0; i < a.length; i++) a[i] = Math.floor(Math.random() * 256); }
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

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
    setVal(formConta, 'token', nova ? novoToken() : '');
    setVal(formConta, 'descricao', nova ? '' : c.descricao);
    setVal(formConta, 'valor', nova ? '' : c.valor);
    setVal(formConta, 'vencimento', nova ? '' : c.vencimento);
    setVal(formConta, 'fornecedor', nova ? '' : c.fornecedor);
    setVal(formConta, 'categoria', nova ? '' : c.categoria);
    setVal(formConta, 'codigo', nova ? '' : c.codigo);
    setVal(formConta, 'observacao', nova ? '' : c.observacao);
    // quem vê: conta nova usa a última escolha; só o dono (ou o administrador principal) muda a de uma conta existente
    var selVis = formConta.elements.visibilidade;
    if (selVis) {
      selVis.value = nova ? visLembrada() : (c.vis === 'publico' ? 'publico' : 'privado');
      selVis.disabled = !nova && c.pode_vis === false;
      textoVis();
    }
    // forma de pagamento, repetir e fotos: só na conta nova (fotos de uma conta existente: pela janela "Fotos")
    setVal(formConta, 'condicao', 'avista');
    setVal(formConta, 'entrada_tipo', '');
    setVal(formConta, 'entrada_valor', '');
    setVal(formConta, 'parcelas', '2');
    setVal(formConta, 'intervalo', 'mensal');
    setVal(formConta, 'venc_parcela', '');
    formConta.elements.venc_parcela.__manual = false;
    setVal(formConta, 'repetir', '');
    setVal(formConta, 'vezes', '');
    contaNova = nova;
    atualizarCampos();
    // parcela de um parcelamento: resumo, "aplicar às outras" e "cancelar parcelamento"
    var cxp = $('[data-cp-parc-box]', dlgConta);
    cxp.hidden = nova || !c.parc;
    if (!cxp.hidden) {
      $('[data-cp-parc-texto]', cxp).textContent = c.parc.rotulo + ' de um parcelamento de R$ ' + c.parc.total + ' (' + c.parc.pagas + ' de ' + c.parc.q + ' já ' + (c.parc.pagas === 1 ? 'paga' : 'pagas') + ').';
      if (formConta.elements.aplicar_parcelas) formConta.elements.aplicar_parcelas.checked = false;
    }
    var fcp = $('#cpFormCancelarParc', dlgConta);
    if (fcp) fcp.elements.conta_id.value = nova ? 0 : c.id;
    // conta que já se repete: caixa com "aplicar às próximas" e "parar de repetir"
    var caixa = $('[data-cp-rec-box]', dlgConta);
    caixa.hidden = nova || !c.rec;
    if (!caixa.hidden) {
      $('[data-cp-rec-texto]', caixa).textContent = c.rec.ativa
        ? 'Esta conta se repete: ' + c.rec.rotulo + '. Cada conta seguinte aparece sozinha 7 dias antes de vencer.'
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
    if (fe) {
      fe.elements.conta_id.value = nova ? 0 : c.id;
      fe.setAttribute('data-confirm', !nova && c.parc
        ? 'Excluir só esta parcela? As outras continuam (para apagar todas, use "Cancelar parcelamento").'
        : 'Excluir esta conta e as fotos dela? Isso não pode ser desfeito.');
    }
    contaAberta = nova ? null : c;
    abrir(dlgConta);
  }
  function textoVis() {
    var sel = formConta.elements.visibilidade, aj = $('[data-cp-vis-ajuda]', dlgConta);
    if (!sel || !aj) return;
    aj.textContent = sel.disabled ? 'Só quem cadastrou a conta (ou o administrador principal) pode mudar isso.'
      : sel.value === 'publico' ? 'Pública: todos os administradores veem esta conta.'
      : 'Privada: só você vê esta conta (e o administrador principal).';
  }
  if (formConta.elements.visibilidade) {
    formConta.elements.visibilidade.addEventListener('change', function () { if (contaNova) visGuardar(this.value); textoVis(); });
  }

  /* ------------------------------------------------------------ à vista x parcelado (e repetir) */
  var contaNova = true;
  var contaAberta = null;   // conta que está na janela de edição

  function numero(txt) {
    var t = String(txt || '').trim();
    if (!t) return NaN;
    return parseFloat(t.indexOf(',') !== -1 ? t.replace(/\./g, '').replace(',', '.') : t);
  }
  function brl(v) { return 'R$ ' + v.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

  /* Mostra só os campos que fazem sentido: à vista x parcelado, com ou sem entrada, repetir... */
  function atualizarCampos() {
    var cond = formConta.elements.condicao.value;
    var ent = formConta.elements.entrada_tipo.value;
    var rep = formConta.elements.repetir.value;
    Array.prototype.forEach.call(dlgConta.querySelectorAll('[data-cp-so-nova]'), function (e) {
      var v = contaNova;
      if (v && e.hasAttribute('data-cp-parc') && cond !== 'parcelado') v = false;
      if (v && e.hasAttribute('data-cp-entrada') && ent === '') v = false;
      if (v && e.hasAttribute('data-cp-repetir') && cond !== 'avista') v = false;
      if (v && e.hasAttribute('data-cp-vezes') && rep === '') v = false;
      e.hidden = !v;
    });
    var parcelado = contaNova && cond === 'parcelado';
    $('[data-cp-rot-valor]', dlgConta).textContent = parcelado ? 'Valor total (R$) *' : 'Valor (R$) *';
    $('[data-cp-rot-venc]', dlgConta).textContent = !parcelado ? 'Vencimento *' : (ent !== '' ? 'Vencimento da entrada *' : 'Vencimento da 1ª parcela *');
    $('[data-cp-rot-entrada]', dlgConta).textContent = ent === 'val' ? 'Valor da entrada (R$)' : 'Entrada (%)';
    var campo = formConta.elements.entrada_valor;
    if (ent === 'val') campo.setAttribute('data-cp-money', ''); else campo.removeAttribute('data-cp-money');
    campo.placeholder = ent === 'val' ? '0,00' : 'Ex.: 30';
    campo.inputMode = ent === 'val' ? 'numeric' : 'decimal';
    resumoParcelas();
  }

  /* Sugere o vencimento da 1ª parcela (um intervalo depois da entrada) enquanto a pessoa não escolher outro. */
  function sugerirVencParcela() {
    var campo = formConta.elements.venc_parcela;
    if (campo.__manual || formConta.elements.entrada_tipo.value === '') return;
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(formConta.elements.vencimento.value);
    if (!m) { campo.value = ''; return; }
    var ano = +m[1], mes = +m[2] - 1, dia = +m[3], iv = formConta.elements.intervalo.value, d;
    if (iv === 'semanal') d = new Date(ano, mes, dia + 7);
    else if (iv === 'quinzenal') d = new Date(ano, mes, dia + 14);
    else { var ult = new Date(ano, mes + 2, 0).getDate(); d = new Date(ano, mes + 1, Math.min(dia, ult)); }   // mês seguinte; dia 31 vira o último dia
    campo.value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  /* "Entrada R$ 300,00 + 3 × R$ 233,33 (a última R$ 233,34) = R$ 1.000,00" */
  function resumoParcelas() {
    var box = $('[data-cp-resumo-parc]', dlgConta);
    if (box.hidden) return;
    var total = numero(formConta.elements.valor.value);
    var n = parseInt(formConta.elements.parcelas.value, 10);
    var tipo = formConta.elements.entrada_tipo.value;
    var cents = Math.round(total * 100), entC = 0;
    if (tipo === 'pct') entC = Math.round(cents * numero(formConta.elements.entrada_valor.value) / 100);
    else if (tipo === 'val') entC = Math.round(numero(formConta.elements.entrada_valor.value) * 100);
    var ok = cents > 0 && n >= 1 && n <= 120 && (tipo === '' || (entC > 0 && entC < cents));
    if (!ok) { box.textContent = 'Preencha o valor total, a entrada e as parcelas para ver como fica.'; return; }
    var resto = cents - entC, base = Math.floor(resto / n), ultima = resto - base * (n - 1);
    var qtd = n + (entC ? 1 : 0);
    box.textContent = (entC ? 'Entrada ' + brl(entC / 100) + ' + ' : '') + n + ' × ' + brl(base / 100)
      + (ultima !== base ? ' (a última ' + brl(ultima / 100) + ')' : '') + ' = ' + brl(cents / 100)
      + ' · ' + qtd + ' contas, numeradas de 1/' + qtd + ' a ' + qtd + '/' + qtd;
  }

  function reagir(e) {
    var nome = e.target && e.target.name;
    if (e.target.matches && e.target.matches('[data-cp-money]')) mascara(e.target);   // a máscara global roda depois deste aviso
    if (e.type === 'change' && nome === 'entrada_tipo') formConta.elements.entrada_valor.value = '';
    if (nome === 'condicao' || nome === 'entrada_tipo' || nome === 'repetir') atualizarCampos();
    if (nome === 'vencimento' || nome === 'intervalo' || nome === 'entrada_tipo' || nome === 'condicao') sugerirVencParcela();
    resumoParcelas();
  }
  formConta.addEventListener('input', reagir);
  formConta.addEventListener('change', reagir);
  formConta.elements.venc_parcela.addEventListener('input', function () { this.__manual = true; });

  $('[data-cp-cancelar-parc]', dlgConta).addEventListener('click', function () {
    var f = $('#cpFormCancelarParc', dlgConta);
    if (f && f.requestSubmit) f.requestSubmit(); else if (f) f.submit();
  });

  $('[data-cp-parar]', dlgConta).addEventListener('click', function () {
    var fp = $('#cpFormParar', dlgConta);
    if (fp && fp.requestSubmit) fp.requestSubmit(); else if (fp) fp.submit();
  });

  $('[data-cp-excluir]', dlgConta).addEventListener('click', function () {
    // conta que se repete: pergunta se é só esta ou se é para parar de repetir também
    if (contaAberta && contaAberta.rec && contaAberta.rec.ativa) {
      var dlgRec = $('#cpDlgExcluirRec');
      Array.prototype.forEach.call(dlgRec.querySelectorAll('input[name="conta_id"]'), function (i) { i.value = contaAberta.id; });
      $('[data-cp-excluir-rec-nome]', dlgRec).textContent = contaAberta.descricao + ' · vence ' + dataBR(contaAberta.vencimento);
      fechar(dlgConta);
      abrir(dlgRec);
      return;
    }
    var fe = $('#cpFormExcluir', dlgConta);
    if (fe && fe.requestSubmit) fe.requestSubmit(); else if (fe) fe.submit();
  });

  /* ------------------------------------------------------------ janela: pagar */
  var dlgPagar = $('#cpDlgPagar');
  var formPagar = $('form[data-cp-form]', dlgPagar);

  function abrirPagar(c) {
    limparArquivos(formPagar);
    setVal(formPagar, 'conta_id', c.id);
    setVal(formPagar, 'token', novoToken());
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
    f.setAttribute('data-confirm', ehPdf(foto) ? 'Excluir este PDF? Isso não pode ser desfeito.' : 'Excluir esta foto? Isso não pode ser desfeito.');
    [['acao', 'del_foto'], ['foto_id', foto.id], ['ver', root.getAttribute('data-ver') || ''], ['q', root.getAttribute('data-q') || ''], ['de', root.getAttribute('data-de') || '']].forEach(function (par) {
      var i = document.createElement('input'); i.type = 'hidden'; i.name = par[0]; i.value = par[1]; f.appendChild(i);
    });
    var b = document.createElement('button');
    b.type = 'submit'; b.setAttribute('aria-label', ehPdf(foto) ? 'Excluir PDF' : 'Excluir foto'); b.title = ehPdf(foto) ? 'Excluir PDF' : 'Excluir foto';
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
      vazio.textContent = 'Nenhum anexo ainda. Tire uma foto, escolha da galeria ou escolha um PDF abaixo.';
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
        var box = document.createElement('div'); box.className = 'cp-foto' + (ehPdf(foto) ? ' cp-foto-pdf' : '');
        if (ehPdf(foto)) {
          // PDF: ícone + nome + Abrir / Baixar (o Baixar funciona dentro do app instalado, sem ir para o Chrome)
          var cartao = document.createElement('div'); cartao.className = 'cp-pdf';
          cartao.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span>';
          var nm = document.createElement('strong'); nm.textContent = foto.nome || 'documento.pdf'; nm.title = foto.nome || '';
          var info = document.createElement('small'); info.textContent = 'PDF' + (foto.tamanho ? ' · ' + tamanhoTxt(foto.tamanho) : '');
          cartao.appendChild(nm); cartao.appendChild(info);
          var acoes = document.createElement('div'); acoes.className = 'cp-pdf-acoes';
          var ver = document.createElement('a'); ver.className = 'vn-mini-btn'; ver.href = urlFoto(foto.id, false); ver.target = '_blank'; ver.rel = 'noopener';
          ver.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">visibility</span>Abrir';
          var bx = document.createElement('a'); bx.className = 'vn-mini-btn'; bx.href = urlFoto(foto.id, false, true);
          bx.setAttribute('data-baixar', ''); bx.setAttribute('data-nome', foto.nome || 'documento.pdf');
          bx.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">download</span>Baixar';
          acoes.appendChild(ver); acoes.appendChild(bx);
          cartao.appendChild(acoes);
          box.appendChild(cartao);
        } else {
          var abrirBtn = document.createElement('button');
          abrirBtn.type = 'button'; abrirBtn.className = 'cp-abrir';
          abrirBtn.setAttribute('aria-label', 'Ampliar foto ' + (foto.nome || ''));
          var img = document.createElement('img'); img.loading = 'lazy'; img.alt = foto.nome || 'Foto'; img.src = urlFoto(foto.id, true);
          abrirBtn.appendChild(img);
          abrirBtn.addEventListener('click', function () {
            var imgs = fotos.filter(function (x) { return !ehPdf(x); });   // o visualizador só passa entre fotos
            abrirViewer(imgs, imgs.indexOf(foto));
          });
          box.appendChild(abrirBtn);
        }
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
    setVal(formFotos, 'token', novoToken());
    setVal(formFotos, 'tipo', c.pago ? 'comprovante' : 'conta');
    $('#cpFotosTitulo', dlgFotos).textContent = 'Anexos — ' + c.descricao;
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

  /* ------------------------------------------------------------ anexos: pré-visualização */
  function revogar(box) { $$('img', box).forEach(function (i) { try { URL.revokeObjectURL(i.src); } catch (e) {} }); }
  root.addEventListener('change', function (e) {
    var inp = e.target;
    if (!inp.matches || !inp.matches('input[data-cp-fotos]')) return;
    var box = inp.parentElement.querySelector('[data-cp-previews]') || inp.closest('form').querySelector('[data-cp-previews]');
    if (!box) return;
    revogar(box);
    box.innerHTML = '';
    Array.prototype.slice.call(inp.files || []).slice(0, 20).forEach(function (f) {
      if (/^image\//.test(f.type)) {
        var img = document.createElement('img');
        img.alt = f.name; img.src = URL.createObjectURL(f);
        box.appendChild(img);
      } else if (f.type === 'application/pdf' || /\.pdf$/i.test(f.name || '')) {
        var t = document.createElement('span'); t.className = 'cp-pdf-previa'; t.title = f.name;
        t.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span>';
        var n = document.createElement('small'); n.textContent = f.name; t.appendChild(n);
        box.appendChild(t);
      }
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
