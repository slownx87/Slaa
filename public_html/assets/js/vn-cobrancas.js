/* =========================================================================
   VN System — Central de Cobranças (cobrancas.php) · v1
   - filtros (situação, tipo, etiqueta, busca) sobre a lista renderizada pelo PHP
   - janelas (<dialog>) de detalhe e de criação
   - formulários [data-async]: enviam por fetch, trocam #cb-root pelo HTML novo e avisam com toast
     (sem JS tudo continua funcionando como formulário normal)
   - montador de parcelas e seleção de peças/serviços
   - material do catálogo: editar (nome, tipo, valor, foto...) e cadastrar novo sem sair de "Cobrar peça ou serviço"
   ========================================================================= */
(function () {
  'use strict';

  var VN = window.VN || {};   // o script é defer e vem depois do vn-ui.js
  var CFG = window.VN_COB || {};
  var state = { status: 'aberto', tipo: '', etq: '', q: '' };
  var abertoAntes = '';   // id do detalhe aberto antes de um envio assíncrono

  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function root() { return document.getElementById('cb-root'); }
  function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
  function parseBR(v) {
    v = String(v || '').replace(/[^0-9,.\-]/g, '');
    if (v.indexOf(',') !== -1) v = v.replace(/\./g, '').replace(',', '.');
    var n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  }
  function moneyBR(n) { return Number(n || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  /* Janela modal fica acima de tudo: com uma aberta, o aviso (toast) precisa morar dentro dela para aparecer. */
  var hostAvisos = null;
  function posicionarAvisos() {
    var host = document.querySelector('.vn-toast-host') || hostAvisos;   // pode estar solto se a janela dele foi trocada
    if (!host) return;
    hostAvisos = host;
    var abertas = document.querySelectorAll('dialog[open]');
    var alvo = abertas.length ? abertas[abertas.length - 1] : document.body;   // com duas janelas empilhadas, o aviso vai na de cima
    if (host.parentNode !== alvo) alvo.appendChild(host);
  }
  if (VN.toast && !VN.toast.__cb) {
    var toastOriginal = VN.toast;
    VN.toast = function () { var t = toastOriginal.apply(VN, arguments); posicionarAvisos(); return t; };
    VN.toast.__cb = true;
  }
  function toast(msg, tipo, ms) { if (VN.toast) return VN.toast(msg, tipo, ms); window.alert(msg); return null; }

  /* ------------------------------------------------------------------ janelas */
  function openDlg(id, empilhar) {
    var d = typeof id === 'string' ? document.getElementById(id) : id;
    if (!d || d.open) return d;
    if (!empilhar) $$('dialog.cb-sheet[open]').forEach(function (o) { if (o !== d) o.close(); });   // empilhar: abre POR CIMA da que está aberta
    if (d.showModal) d.showModal(); else d.setAttribute('open', 'open');
    var f = d.querySelector('[data-foco]') || d.querySelector('input:not([type=hidden]):not([disabled]), select');
    if (f && !window.matchMedia('(max-width:700px)').matches) { try { f.focus({ preventScroll: true }); } catch (e) {} }
    return d;
  }
  function closeDlg(d) { if (d && d.open) d.close(); }

  /* ------------------------------------------------------------------ filtros */
  var REGRA = {
    aberto:    function (e) { return e !== 'paga'; },
    atrasadas: function (e) { return e === 'atrasada'; },
    semana:    function (e) { return e === 'hoje' || e === 'semana'; },
    pagas:     function (e) { return e === 'paga'; }
  };

  function aplicar() {
    var r = root();
    if (!r) return;
    var q = norm(state.q).trim();
    var regra = REGRA[state.status] || REGRA.aberto;
    var cont = { aberto: 0, atrasadas: 0, semana: 0, pagas: 0 };
    var visiveis = 0;

    $$('.cb-row', r).forEach(function (el) {
      var estado = el.getAttribute('data-estado');
      var base = (state.tipo === '' || el.getAttribute('data-tipo') === state.tipo) &&
                 (state.etq === '' || (state.etq === '__sem__' ? el.getAttribute('data-etq') === '' : el.getAttribute('data-etq') === state.etq)) &&
                 (q === '' || norm(el.getAttribute('data-busca')).indexOf(q) !== -1);
      if (base) { Object.keys(cont).forEach(function (k) { if (REGRA[k](estado)) cont[k]++; }); }
      var mostra = base && regra(estado);
      el.hidden = !mostra;
      if (mostra) visiveis++;
    });

    $$('.cb-sec', r).forEach(function (sec) {
      var n = 0, soma = 0;
      $$('.cb-row', sec).forEach(function (el) { if (!el.hidden) { n++; soma += parseFloat(el.getAttribute('data-valor') || '0') || 0; } });
      sec.hidden = n === 0;
      var c = $('.cb-count', sec); if (c) c.textContent = n;
      var s = $('.cb-sum', sec); if (s) s.textContent = 'R$ ' + moneyBR(soma);
    });

    $$('[data-status]', r).forEach(function (b) {
      var k = b.getAttribute('data-status');
      b.setAttribute('aria-pressed', k === state.status ? 'true' : 'false');
      var n = $('.n', b); if (n) n.textContent = cont[k];
    });
    $$('[data-kpi]', r).forEach(function (b) {
      var k = b.getAttribute('data-kpi');
      var ativo = k === 'material' ? (state.tipo === 'material') : (state.status === k && state.tipo === '');
      b.setAttribute('aria-pressed', ativo ? 'true' : 'false');
    });

    var vazio = $('#cb-vazio-filtro', r);
    if (vazio) {
      var temLinhas = $$('.cb-row', r).length > 0;
      vazio.hidden = !(temLinhas && visiveis === 0);
      var msg = $('.msg', vazio);
      if (msg) {
        msg.textContent = state.status === 'atrasadas' ? 'Nenhuma cobrança atrasada. Tudo em dia!' :
                          state.status === 'pagas' ? 'Nenhum pagamento recente para mostrar.' :
                          state.status === 'semana' ? 'Nada vence nos próximos 7 dias.' :
                          'Nenhuma cobrança encontrada com esses filtros.';
      }
    }
  }

  function sincronizarTools() {
    var q = $('#cb-q'); if (q && q.value !== state.q) q.value = state.q;
    var t = $('#cb-tipo'); if (t) t.value = state.tipo;
    var e = $('#cb-etq'); if (e) e.value = state.etq;
  }

  function definir(parte) {
    Object.keys(parte).forEach(function (k) { state[k] = parte[k]; });
    sincronizarTools();
    aplicar();
  }

  /* ------------------------------------------------------------------ envio assíncrono */
  function postar(fd) {
    return fetch(window.location.pathname, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    }).then(function (r) { return r.text(); }).then(function (html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      return { doc: doc, novoRoot: doc.getElementById('cb-root'), flash: doc.getElementById('cb-flash') };
    });
  }

  function trocarRoot(novo) {
    var r = root();
    if (!r || !novo) return;
    var y = window.scrollY;
    var host = document.querySelector('.vn-toast-host');
    if (host && r.contains(host)) document.body.appendChild(host);   // não perde os avisos junto com a janela trocada
    r.innerHTML = novo.innerHTML;
    sincronizarTools();
    aplicar();
    window.scrollTo(0, y);
  }

  function enviar(form, submitter) {
    var fd = new FormData(form);
    if (submitter && submitter.name) fd.append(submitter.name, submitter.value);
    var botao = submitter || form.querySelector('button[type="submit"]');
    var dlgForm = form.closest('dialog');
    var dentroDoRoot = !!form.closest('#cb-root');
    abertoAntes = (dlgForm && dentroDoRoot && !form.hasAttribute('data-fecha')) ? dlgForm.id : '';   // data-fecha: fecha ao salvar
    var desfazer = form.hasAttribute('data-undo') ? new FormData(form) : null;
    var rotuloOriginal = botao ? botao.innerHTML : '';
    if (botao) { botao.disabled = true; botao.setAttribute('aria-busy', 'true'); }

    return postar(fd).then(function (res) {
      if (!res.novoRoot || !res.flash) { toast('Sua sessão pode ter expirado. Recarregando…', 'warn'); setTimeout(function () { window.location.reload(); }, 900); return; }
      var erro = res.flash.getAttribute('data-erro') || '';
      var ok = res.flash.getAttribute('data-ok') || '';
      if (erro) { toast(erro, 'err', 6000); return; }

      trocarRoot(res.novoRoot);
      if (dlgForm && !dentroDoRoot) { form.reset(); resetarMontadores(dlgForm); closeDlg(dlgForm); }
      var alvo = res.flash.getAttribute('data-abrir') || abertoAntes;
      if (alvo && document.getElementById(alvo)) openDlg(alvo);
      else if (abertoAntes) closeDlg(document.getElementById(abertoAntes));

      if (desfazer && ok) {
        var t = toast(ok, 'ok', 8000);
        if (t) {
          var b = document.createElement('button');
          b.type = 'button'; b.className = 'cb-undo'; b.textContent = 'Desfazer';
          b.addEventListener('click', function () {
            if (t.parentNode) t.parentNode.removeChild(t);
            desfazer.set('pago', '0');
            postar(desfazer).then(function (r2) {
              if (r2.novoRoot) trocarRoot(r2.novoRoot);
              var e2 = r2.flash && r2.flash.getAttribute('data-erro');
              toast(e2 ? e2 : 'Pagamento desfeito.', e2 ? 'err' : 'ok');
            }).catch(function () { toast('Não foi possível desfazer. Tente de novo.', 'err'); });
          });
          t.appendChild(b);
        }
      } else if (ok) {
        toast(ok, 'ok', 5000);
      }
    }).catch(function () {
      toast('Sem conexão com o servidor. Nada foi salvo.', 'err', 6000);
    }).then(function () {
      if (botao && document.body.contains(botao)) { botao.disabled = false; botao.removeAttribute('aria-busy'); botao.innerHTML = rotuloOriginal; }
    });
  }

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented || !f || !f.matches || !f.matches('form[data-async]')) return;   // confirmação pendente (vn-ui.js)
    if (f.matches('[data-pb-form]') && !validarParcelas(f)) { e.preventDefault(); return; }
    if (f.id === 'cb-form-peca' && !validarPecas(f)) { e.preventDefault(); return; }
    e.preventDefault();
    enviar(f, e.submitter || null);
  });

  /* ------------------------------------------------------------------ montador de parcelas */
  function pbRows(b) { return $$('[data-pb-rows] .cb-pr', b); }
  function pbRefresh(b) {
    var n = 0, total = 0;
    pbRows(b).forEach(function (r) {
      n++;
      var num = $('.n', r); if (num) num.textContent = n;
      var v = $('[name="parc_valor[]"]', r); total += v ? parseBR(v.value) : 0;
    });
    var t = $('[data-pb-total]', b); if (t) t.textContent = 'R$ ' + moneyBR(total);
    var c = $('[data-pb-count]', b); if (c) c.textContent = n;
    var h = $('[data-pb-hint]', b); if (h) h.hidden = n > 0;
  }
  function pbAdd(b, venc, valor) {
    var rows = $('[data-pb-rows]', b);
    var d = document.createElement('div');
    d.className = 'cb-pr';
    d.innerHTML = '<span class="n"></span>' +
      '<input type="date" name="parc_venc[]" required aria-label="Vencimento">' +
      '<input name="parc_valor[]" inputmode="decimal" required aria-label="Valor da parcela">' +
      '<input type="hidden" name="parc_id[]" value="0">' +
      '<button type="button" class="del" data-pb-del aria-label="Remover parcela">×</button>';
    $('[name="parc_venc[]"]', d).value = venc || '';
    $('[name="parc_valor[]"]', d).value = valor || '';
    rows.appendChild(d);
    pbRefresh(b);
    return d;
  }
  function addMonths(iso, k) {
    var p = String(iso || '').split('-'); if (p.length !== 3) return iso || '';
    var y = +p[0], m = +p[1], d = +p[2], idx = (m - 1) + k;
    y += Math.floor(idx / 12); m = ((idx % 12) + 12) % 12 + 1;
    var last = new Date(y, m, 0).getDate();
    return y + '-' + String(m).padStart(2, '0') + '-' + String(Math.min(d, last)).padStart(2, '0');
  }
  function pbGerar(b) {
    var gt = $('[data-pb-gt]', b), gq = $('[data-pb-gq]', b), gd = $('[data-pb-gd]', b);
    if (!gt) return;
    var total = parseBR(gt.value);
    var qtd = Math.max(1, Math.min(60, parseInt(gq && gq.value, 10) || 1));
    var primeira = (gd && gd.value) || b.getAttribute('data-hoje') || '';
    pbRows(b).forEach(function (r) { r.remove(); });
    if (total > 0) {
      var cent = Math.round(total * 100), base = Math.floor(cent / qtd), sobra = cent - base * qtd;
      for (var k = 0; k < qtd; k++) pbAdd(b, addMonths(primeira, k), moneyBR((base + (k === qtd - 1 ? sobra : 0)) / 100));
    }
    pbRefresh(b);
  }
  function resetarMontadores(escopo) {
    $$('[data-pb]', escopo).forEach(function (b) {
      pbRows(b).forEach(function (r) { r.remove(); });
      var gq = $('[data-pb-gq]', b); if (gq) gq.value = '1';
      var gd = $('[data-pb-gd]', b); if (gd) gd.value = b.getAttribute('data-hoje') || '';
      pbRefresh(b);
    });
  }
  function validarParcelas(form) {
    var b = $('[data-pb]', form);
    if (!b) return true;
    if (pbRows(b).length === 0) { toast('Informe o valor total para gerar as parcelas, ou adicione uma parcela.', 'warn'); return false; }
    return true;
  }

  document.addEventListener('input', function (e) {
    var t = e.target, b = t.closest && t.closest('[data-pb]');
    if (!b) return;
    if (t.matches('[data-pb-gt],[data-pb-gq],[data-pb-gd]')) { if (b.hasAttribute('data-pb-auto')) pbGerar(b); }
    else if (t.matches('[name="parc_valor[]"]')) pbRefresh(b);
  });
  document.addEventListener('click', function (e) {
    var t = e.target;
    var add = t.closest('[data-pb-add]');
    if (add) { var b = add.closest('[data-pb]'); var gd = $('[data-pb-gd]', b); pbAdd(b, (gd && gd.value) || b.getAttribute('data-hoje') || '', ''); return; }
    var del = t.closest('[data-pb-del]');
    if (del) { var b2 = del.closest('[data-pb]'); del.closest('.cb-pr').remove(); pbRefresh(b2); }
  });

  /* ------------------------------------------------------------------ cliente -> telefone */
  document.addEventListener('change', function (e) {
    var s = e.target;
    if (!s.matches || !s.matches('select[data-cliente-tel]')) return;
    var f = s.closest('form'), tel = f && $('input[name="telefone"]', f);
    var opt = s.options[s.selectedIndex];
    var fone = opt ? (opt.getAttribute('data-telefone') || '') : '';
    if (tel && fone && (tel.value.trim() === '' || tel.getAttribute('data-auto') === '1')) { tel.value = fone; tel.setAttribute('data-auto', '1'); }
  });
  document.addEventListener('input', function (e) {
    if (e.target.matches && e.target.matches('input[name="telefone"]')) e.target.removeAttribute('data-auto');
  });

  /* ------------------------------------------------------------------ peças / serviços (venda de material) */
  function pecaSync() {
    var m = document.getElementById('cb-peca-modal'); if (!m) return;
    var n = $$('[data-sel].marcado', m).length;
    $$('[data-serv-row] input[name="serv_nome[]"]', m).forEach(function (i) { if (i.value.trim() !== '') n++; });
    var b = document.getElementById('cb-peca-ok');
    if (b) { b.disabled = n === 0; b.textContent = n > 0 ? 'Cobrar ' + n + (n === 1 ? ' item' : ' itens') : 'Cobrar itens selecionados'; }
    return n;
  }
  function validarPecas() {
    if (!pecaSync()) { toast('Selecione ao menos uma peça ou escreva um serviço.', 'warn'); return false; }
    return true;
  }
  function abrirPeca(cid, nome) {
    var m = document.getElementById('cb-peca-modal'); if (!m) return;
    var sel = document.getElementById('cb-peca-cli'), nomeIn = document.getElementById('cb-peca-nome');
    var usar = '';
    if (sel) { sel.value = (cid && cid !== '0') ? cid : ''; if (sel.value === '') usar = nome || ''; } else usar = nome || '';
    if (nomeIn) nomeIn.value = usar;
    if (sel) { if (usar) sel.removeAttribute('required'); else sel.setAttribute('required', ''); }
    openDlg(m);
  }
  document.addEventListener('click', function (e) {
    var card = e.target.closest('[data-sel]');
    if (card && !e.target.closest('.campos, [data-cat-edit]')) {
      var on = card.classList.toggle('marcado');
      $$('.campos input', card).forEach(function (i) { i.disabled = !on; });
      pecaSync();
    }
    var mais = e.target.closest('#cb-serv-add');
    if (mais) {
      var rows = document.getElementById('cb-serv-rows'), base = rows && $('[data-serv-row]', rows);
      if (!base) return;
      var nova = base.cloneNode(true);
      $$('input', nova).forEach(function (i) { i.value = i.name === 'serv_qtd[]' ? '1' : ''; });
      rows.appendChild(nova);
      $('input[name="serv_nome[]"]', nova).focus();
    }
  });
  document.addEventListener('input', function (e) {
    var t = e.target;
    if (t.name === 'serv_nome[]') pecaSync();
    if (t.id === 'cb-peca-busca') {
      var q = norm(t.value).trim();
      $$('[data-sel]', document.getElementById('cb-peca-modal')).forEach(function (c) {
        c.hidden = q !== '' && norm(c.getAttribute('data-busca')).indexOf(q) === -1;
      });
    }
    if (t.id === 'cb-q') definir({ q: t.value });
  });

  /* ------------------------------------------------------------------ material do catálogo: editar / cadastrar */
  function catForm() { return document.getElementById('cb-form-cat'); }
  function catTipoAjustar() {
    var f = catForm(); if (!f) return;
    var servico = f.elements.catalogo_tipo.value === 'servico' || f.elements.catalogo_tipo.value === 'mao_obra';
    $$('[data-cat-fisico]', f).forEach(function (el) { el.hidden = servico; });   // serviço não tem unidade nem estoque
  }
  function abrirCat(dados) {
    var d = document.getElementById('cb-cat-modal'), f = catForm();
    if (!d || !f) return;
    f.reset();
    var novo = !dados;
    var busca = document.getElementById('cb-peca-busca');
    $('[data-cat-titulo]', d).textContent = novo ? 'Novo material' : 'Editar material';
    $('[data-cat-ok]', d).textContent = novo ? 'Cadastrar material' : 'Salvar material';
    f.elements.catalogo_id.value = novo ? '0' : String(dados.id);
    f.elements.catalogo_estoque_orig.value = novo ? '' : dados.estoque;
    f.elements.catalogo_nome.value = novo ? ((busca && busca.value.trim()) || '') : dados.nome;   // novo: aproveita o que foi buscado
    var tipos = $$('option', f.elements.catalogo_tipo).map(function (o) { return o.value; });
    f.elements.catalogo_tipo.value = (!novo && tipos.indexOf(dados.tipo) !== -1) ? dados.tipo : 'material';
    var servico = !novo && (dados.tipo === 'servico' || dados.tipo === 'mao_obra');
    f.elements.catalogo_linha.value = novo ? 'cftv' : (dados.linha || (servico ? 'geral' : 'cftv'));
    f.elements.catalogo_valor.value = novo ? '' : dados.valor;
    f.elements.catalogo_custo.value = novo ? '' : dados.custo;
    f.elements.catalogo_unidade.value = novo ? 'un' : (dados.unidade || 'un');
    f.elements.catalogo_quantidade_estoque.value = novo ? '0,00' : dados.estoque;
    f.elements.catalogo_descricao.value = novo ? '' : dados.descricao;
    var img = $('[data-cat-foto-img]', d), dica = $('[data-cat-foto-dica]', d);
    if (img.__url) { try { URL.revokeObjectURL(img.__url); } catch (e) {} img.__url = null; }
    if (!novo && dados.foto) { img.src = dados.foto; img.hidden = false; dica.textContent = 'Foto atual. Escolha outra só se quiser trocar.'; }
    else { img.removeAttribute('src'); img.hidden = true; dica.textContent = 'Tire uma foto ou escolha da galeria.'; }
    catTipoAjustar();
    openDlg(d, true);
    var campo = novo ? f.elements.catalogo_nome : f.elements.catalogo_valor;
    if (!window.matchMedia('(max-width:700px)').matches) { try { campo.focus(); } catch (e) {} }
  }
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !t.matches) return;
    if (t.matches('#cb-form-cat select[name="catalogo_tipo"]')) catTipoAjustar();
    if (t.id === 'cb-cat-foto') {
      var img = $('[data-cat-foto-img]', document.getElementById('cb-cat-modal')), arq = t.files && t.files[0];
      if (img && arq) {
        if (img.__url) { try { URL.revokeObjectURL(img.__url); } catch (e2) {} }
        img.__url = URL.createObjectURL(arq); img.src = img.__url; img.hidden = false;
      }
    }
  });
  document.addEventListener('click', function (e) {
    var ed = e.target.closest('[data-cat-edit]');
    if (ed) {
      var card = ed.closest('[data-cat]'), dados = null;
      try { dados = JSON.parse(card.getAttribute('data-cat')); } catch (err) { dados = null; }
      if (dados) abrirCat(dados);
      return;
    }
    if (e.target.closest('[data-cat-novo]')) abrirCat(null);
  });

  /* O que já estava marcado em "Cobrar peça ou serviço" (quantidade e valor digitados) para não perder ao trocar a lista. */
  function capturarSelecao() {
    var m = document.getElementById('cb-peca-modal'), est = {};
    if (!m) return est;
    $$('[data-sel]', m).forEach(function (c) {
      var q = $('[name="peca_qtd[]"]', c), v = $('[name="peca_valor[]"]', c);
      est[c.getAttribute('data-cat-id')] = { on: c.classList.contains('marcado'), qtd: q ? q.value : '', valor: v ? v.value : '' };
    });
    return est;
  }
  function restaurarSelecao(est, editadoId, novoId) {
    var m = document.getElementById('cb-peca-modal'); if (!m) return;
    $$('[data-sel]', m).forEach(function (c) {
      var id = c.getAttribute('data-cat-id'), e = est[id];
      var marcar = !!(e && e.on) || (novoId && String(novoId) === id);   // material recém-cadastrado já vem marcado
      if (!marcar) return;
      c.classList.add('marcado');
      $$('.campos input', c).forEach(function (i) { i.disabled = false; });
      if (e && e.on) {
        var q = $('[name="peca_qtd[]"]', c), v = $('[name="peca_valor[]"]', c);
        if (q) q.value = e.qtd;
        if (v && String(editadoId) !== id) v.value = e.valor;   // o valor do material que acabou de ser editado vale o novo
      }
    });
    var busca = document.getElementById('cb-peca-busca');
    if (busca && busca.value) busca.dispatchEvent(new Event('input', { bubbles: true }));
    pecaSync();
  }

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || !f.matches || !f.matches('form[data-cat-form]')) return;
    e.preventDefault();
    if (f.__busy) return;
    f.__busy = true;
    var btn = $('[data-cat-ok]', f), rotulo = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Salvando…'; }
    var livre = function () { f.__busy = false; if (btn) { btn.disabled = false; btn.textContent = rotulo; } };
    var estado = capturarSelecao();
    var idEditado = parseInt(f.elements.catalogo_id.value, 10) || 0;
    postar(new FormData(f)).then(function (res) {
      if (!res.novoRoot || !res.flash) { toast('Sua sessão pode ter expirado. Recarregando…', 'warn'); setTimeout(function () { window.location.reload(); }, 900); return; }
      var erro = res.flash.getAttribute('data-erro') || '';
      if (erro) { toast(erro, 'err', 6000); return; }   // a janela continua aberta para corrigir
      var nova = res.doc.getElementById('cb-peca-lista'), lista = document.getElementById('cb-peca-lista');
      if (nova && lista) {
        lista.innerHTML = nova.innerHTML;
        var salvoId = parseInt(res.flash.getAttribute('data-cat-id') || '0', 10) || 0;
        var eNovo = res.flash.getAttribute('data-cat-novo') === '1';
        restaurarSelecao(estado, idEditado || salvoId, eNovo ? salvoId : 0);
      }
      closeDlg(document.getElementById('cb-cat-modal'));
      var ok = res.flash.getAttribute('data-ok'); if (ok) toast(ok, 'ok', 5000);
    }).catch(function () {
      toast('Sem conexão com o servidor. Nada foi salvo.', 'err', 6000);
    }).then(livre);
  });

  /* ------------------------------------------------------------------ cliques gerais */
  document.addEventListener('click', function (e) {
    var t = e.target;

    var fechar = t.closest('[data-fechar]');
    if (fechar) { closeDlg(fechar.closest('dialog')); return; }

    var abrir = t.closest('[data-abrir]');
    if (abrir) {
      e.preventDefault();
      var menu = abrir.closest('details.cb-menu'); if (menu) menu.removeAttribute('open');
      openDlg(abrir.getAttribute('data-abrir'));
      return;
    }

    var alt = t.closest('[data-alternar]');
    if (alt) {
      var alvoEdicao = document.getElementById(alt.getAttribute('data-alternar'));
      if (alvoEdicao) { alvoEdicao.hidden = !alvoEdicao.hidden; if (!alvoEdicao.hidden) { var fi = $('input', alvoEdicao); if (fi) fi.focus(); } }
      return;
    }

    var peca = t.closest('[data-add-peca]');
    if (peca) { abrirPeca(peca.getAttribute('data-cliente-id'), peca.getAttribute('data-cliente-nome')); return; }

    var st = t.closest('#cb-root [data-status]');
    if (st) { definir({ status: st.getAttribute('data-status') }); return; }

    var kpi = t.closest('#cb-root [data-kpi]');
    if (kpi) {
      var k = kpi.getAttribute('data-kpi');
      if (k === 'material') definir({ status: 'aberto', tipo: state.tipo === 'material' ? '' : 'material' });
      else definir({ status: state.status === k && state.tipo === '' ? 'aberto' : k, tipo: '' });
      return;
    }

    var limpar = t.closest('[data-limpar-filtros]');
    if (limpar) { definir({ status: 'aberto', tipo: '', etq: '', q: '' }); return; }

    var linha = t.closest('#cb-root .cb-row');
    if (linha && !t.closest('a,button,form,input,select,textarea,summary,label')) { openDlg(linha.getAttribute('data-dlg')); return; }

    // fecha o menu "Mais" ao clicar fora
    $$('details.cb-menu[open]').forEach(function (m) { if (!m.contains(t)) m.removeAttribute('open'); });
  });

  document.addEventListener('click', function (e) {
    var d = e.target;
    if (d && d.matches && d.matches('dialog.cb-sheet')) closeDlg(d);   // clique no fundo escurecido
  });

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.id === 'cb-tipo') definir({ tipo: t.value });
    if (t.id === 'cb-etq') definir({ etq: t.value });
  });

  /* ------------------------------------------------------------------ detalhe: ver <-> editar */
  function vista(d, ir) {
    $$('[data-vista]', d).forEach(function (s) { s.hidden = s.getAttribute('data-vista') !== ir; });
    d.setAttribute('data-modo', ir);
    $$('[data-pb]', d).forEach(pbRefresh);
    var corpo = $('.cb-sheet-body', d); if (corpo) corpo.scrollTop = 0;
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-vista-ir]');
    if (!b) return;
    var d = b.closest('dialog'), ir = b.getAttribute('data-vista-ir');
    vista(d, ir);
    if (ir === 'editar') { var f = $('[data-vista="editar"] input:not([type=hidden])', d); if (f && !window.matchMedia('(max-width:700px)').matches) f.focus(); }
  });
  document.addEventListener('close', function (e) {   // 'close' não borbulha: captura
    var d = e.target;
    if (d && d.matches && d.matches('dialog.cb-sheet[data-modo="editar"]')) vista(d, 'ver');
  }, true);

  /* ------------------------------------------------------------------ início */
  function iniciar() {
    var hash = (window.location.hash || '').replace('#', '');
    var porHash = {
      atrasadas: { status: 'atrasadas' }, semana: { status: 'semana' }, pagas: { status: 'pagas' },
      material: { tipo: 'material' }, vendas: { tipo: 'material' },
      avulsas: { tipo: 'parcela' }, mensalidades: { tipo: 'mensalidade' }
    };
    if (porHash[hash]) { Object.keys(porHash[hash]).forEach(function (k) { state[k] = porHash[hash][k]; }); }
    sincronizarTools();
    aplicar();
    if (hash === 'etiquetas') openDlg('cb-dlg-etq');

    var fl = document.getElementById('cb-flash');
    var abrir = fl && fl.getAttribute('data-abrir');
    if (abrir && document.getElementById(abrir)) openDlg(abrir);

    // pedido aprovado (?orc=ID): abre "Cobrança em parcelas" já preenchida
    if (CFG.orc) {
      var d = document.getElementById('cb-dlg-parcelas');
      if (d) {
        var cli = $('select[name="cliente_id"]', d);
        if (cli && CFG.orc.cliente_id) { cli.value = String(CFG.orc.cliente_id); cli.dispatchEvent(new Event('change', { bubbles: true })); }
        var tel = $('input[name="telefone"]', d); if (tel && CFG.orc.telefone && !tel.value) tel.value = CFG.orc.telefone;
        var obs = $('textarea[name="observacao"]', d); if (obs) obs.value = CFG.orc.obs || '';
        var mais = $('.cb-more', d); if (mais) mais.open = true;
        var gt = $('[data-pb-gt]', d);
        if (gt && CFG.orc.valor > 0) { gt.value = moneyBR(CFG.orc.valor); var b = $('[data-pb]', d); if (b) pbGerar(b); }
        openDlg(d);
      }
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
})();
