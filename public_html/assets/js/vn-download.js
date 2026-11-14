/* =========================================================================
   VN System — baixar arquivo sem sair do app
   O app instalado no celular (PWA) não consegue "imprimir em PDF" nem abrir um PDF numa aba: o
   Android manda abrir no Chrome. Este ajudante busca o arquivo na própria página e entrega como
   download comum, então funciona dentro do app.

     VN.baixar(url, nome)        busca a url e salva como arquivo (devolve uma Promise)
     VN.compartilhar(url, nome)  abre a folha de compartilhar do celular (WhatsApp etc.) com o arquivo
     <a href="..." data-baixar data-nome="orcamento.pdf">   baixa ao tocar (sem JS vira link normal)
     <a href="..." data-compartilhar data-nome="orcamento.pdf">

   Dentro do aplicativo Android (window.VNApp), o arquivo é entregue ao app, que salva em Downloads/VN System e abre
   no leitor de PDF. O servidor precisa devolver o arquivo de verdade (PDF/imagem), não uma página HTML.
   ========================================================================= */
(function (w, d) {
  'use strict';
  var VN = w.VN = w.VN || {};

  function aviso(msg, tipo) {
    if (VN.toast) VN.toast(msg, tipo);
    else if (tipo === 'err') w.alert(msg);
  }

  function nomeDoCabecalho(resp) {
    var cd = (resp.headers && resp.headers.get && resp.headers.get('Content-Disposition')) || '';
    var m = /filename\*\s*=\s*UTF-8''([^;]+)/i.exec(cd) || /filename\s*=\s*"?([^";]+)"?/i.exec(cd);
    if (!m) return '';
    try { return decodeURIComponent(m[1]); } catch (e) { return m[1]; }
  }

  function limparNome(n) {
    n = String(n || '').replace(/[\\/:*?"<>|\u0000-\u001f]+/g, '_').replace(/\s+/g, ' ').trim();
    return n.slice(0, 120);
  }

  /* Busca o arquivo; falha com mensagem clara se o servidor devolveu uma página (ex.: sessão expirada). */
  function buscar(url) {
    return w.fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function (r) {
      var tipo = (r.headers.get('Content-Type') || '').toLowerCase();
      if (!r.ok) throw new Error(r.status === 404 ? 'Arquivo não encontrado.' : 'Não foi possível baixar (erro ' + r.status + ').');
      if (tipo.indexOf('text/html') === 0) throw new Error('Sua sessão pode ter expirado. Entre de novo e tente outra vez.');
      return r.blob().then(function (blob) { return { blob: blob, nome: nomeDoCabecalho(r), tipo: tipo }; });
    });
  }

  /* Aplicativo Android: manda o conteúdo (base64) para o app salvar e abrir. Devolve true se o app assumiu o arquivo. */
  function entregarAoApp(blob, nome) {
    var app = w.VNApp;
    if (!app || typeof app.salvarBase64 !== 'function' || !w.FileReader) return false;
    var leitor = new w.FileReader();
    leitor.onload = function () {
      var s = String(leitor.result || ''), i = s.indexOf(',');
      try { app.salvarBase64(i >= 0 ? s.slice(i + 1) : '', nome, blob.type || ''); }
      catch (e) { aviso('Não foi possível salvar o arquivo.', 'err'); }
    };
    leitor.onerror = function () { aviso('Não foi possível ler o arquivo.', 'err'); };
    leitor.readAsDataURL(blob);
    return true;
  }

  function salvarBlob(blob, nome) {
    if (entregarAoApp(blob, nome)) return true;
    var navAntigo = w.navigator && w.navigator.msSaveOrOpenBlob;
    if (navAntigo) { w.navigator.msSaveOrOpenBlob(blob, nome); return false; }
    var url = w.URL.createObjectURL(blob);
    var a = d.createElement('a');
    a.href = url;
    a.download = nome;
    a.rel = 'noopener';
    a.style.display = 'none';
    d.body.appendChild(a);
    a.click();
    setTimeout(function () { d.body.removeChild(a); w.URL.revokeObjectURL(url); }, 4000);
    return false;
  }

  VN.baixar = function (url, nome) {
    if (!w.fetch || !w.Blob || !w.URL || !w.URL.createObjectURL) {   // navegador muito antigo: link normal
      w.location.href = url;
      return Promise.resolve();
    }
    aviso('Baixando…');
    return buscar(url).then(function (r) {
      var final = limparNome(nome || r.nome || 'arquivo');
      if (!/\.[a-z0-9]{2,5}$/i.test(final) && r.tipo.indexOf('pdf') !== -1) final += '.pdf';
      if (!salvarBlob(r.blob, final)) aviso('Pronto: ' + final, 'ok');   // no app, o próprio app avisa onde salvou
    }).catch(function (e) {
      aviso(e && e.message ? e.message : 'Não foi possível baixar o arquivo.', 'err');
    });
  };

  VN.compartilhar = function (url, nome) {
    var nav = w.navigator;
    if (!nav || !nav.share || !w.File) return VN.baixar(url, nome);   // sem compartilhar no aparelho: baixa
    aviso('Preparando…');
    return buscar(url).then(function (r) {
      var final = limparNome(nome || r.nome || 'arquivo');
      var tipo = r.tipo.split(';')[0] || 'application/octet-stream';
      var arq = new w.File([r.blob], final, { type: tipo });
      if (nav.canShare && !nav.canShare({ files: [arq] })) { if (!salvarBlob(r.blob, final)) aviso('Pronto: ' + final, 'ok'); return; }
      return nav.share({ files: [arq], title: final }).catch(function (e) { if (e && e.name === 'AbortError') return; throw e; });
    }).catch(function (e) {
      aviso(e && e.message ? e.message : 'Não foi possível compartilhar o arquivo.', 'err');
    });
  };

  /* Liga os botões/links marcados. Um toque duplo não baixa duas vezes. */
  d.addEventListener('click', function (e) {
    var alvo = e.target && e.target.closest ? e.target : (e.target && e.target.parentElement);
    if (!alvo) return;
    var el = alvo.closest('[data-baixar],[data-compartilhar]');
    if (!el || e.defaultPrevented) return;
    if (e.button && e.button !== 0) return;
    if (e.ctrlKey || e.metaKey || e.shiftKey) return;   // abrir em nova aba continua funcionando
    var compartilhar = el.hasAttribute('data-compartilhar');
    var url = el.getAttribute('data-url') || el.getAttribute('href');
    if (!url || url === '#') return;
    e.preventDefault();
    if (el.getAttribute('aria-busy') === 'true') return;
    el.setAttribute('aria-busy', 'true');
    var nome = el.getAttribute('data-nome') || '';
    var fim = function () { el.removeAttribute('aria-busy'); };
    (compartilhar ? VN.compartilhar(url, nome) : VN.baixar(url, nome)).then(fim, fim);
  });
})(window, document);
